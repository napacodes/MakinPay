<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\AgentStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Agent;
use App\Models\PaymentLink;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ApiMoney;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * What a scanned code is.
 *
 * Four things a camera meets: a person's QR (`digikash://pay?…`), a
 * payment link somebody shared, an agent's counter QR (its cash-out link,
 * or the agent code printed beside it), and a merchant's checkout reference
 * at a counter. Each is looked up here so the app shows who is being paid
 * before anything is typed, and nothing the code carries is trusted for
 * more than a lookup - a person's QR names a username, not a wallet.
 */
class PayCodeResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(string $code): array
    {
        $code = trim($code);

        if ($code === '') {
            return ['type' => 'unknown'];
        }

        if (preg_match('#^digikash://pay(\?.*)?$#i', $code) === 1) {
            return $this->person($code);
        }

        if (preg_match('#/payment-link/([A-Za-z0-9_-]+)#', $code, $m) === 1) {
            return $this->link($m[1]);
        }

        if (preg_match('#/agent/qr/([^/?\#\s]+)#', $code, $m) === 1) {
            return $this->agent($m[1]);
        }

        // A counter charge's QR: its short public page.
        if (preg_match('#/payment/pay/[^/?\#\s]+/([A-Za-z0-9]{32})(?:[/?\#]|$)#', $code, $m) === 1) {
            $checkout = Transaction::query()->where('trx_token', $m[1])->first();

            return $checkout === null ? ['type' => 'unknown'] : $this->merchant($checkout->trx_id);
        }

        if (preg_match('#^https?://#i', $code) === 1) {
            return $this->signedCheckout($code);
        }

        $agent = $this->agent($code);
        if ($agent['type'] === 'agent') {
            return $agent;
        }

        return $this->merchant($code);
    }

    /**
     * @return array<string, mixed>
     */
    private function person(string $code): array
    {
        parse_str((string) parse_url($code, PHP_URL_QUERY), $query);

        $wallet = null;
        $user   = null;

        if (! empty($query['wallet'])) {
            $wallet = Wallet::query()->where('uuid', strtoupper(trim((string) $query['wallet'])))->with(['user', 'currency'])->first();
            $user   = $wallet?->user;
        } elseif (! empty($query['u'])) {
            $user = User::query()->where('username', trim((string) $query['u']))->first();
            if ($user !== null && ! empty($query['w'])) {
                $wallet = Wallet::query()->where('user_id', $user->id)->whereHas('currency', fn ($q) => $q->where('code', strtoupper(trim((string) $query['w']))))->with('currency')->first();
            }
        }

        if ($user === null) {
            return ['type' => 'unknown'];
        }

        $currency = $wallet?->currency?->code ?? (isset($query['cur']) ? strtoupper((string) $query['cur']) : null);
        $amount   = isset($query['amount']) && is_numeric($query['amount']) && $currency !== null ? ApiMoney::amount((string) $query['amount'], $currency) : null;

        return [
            'type'      => 'person',
            'user'      => CounterpartyResolver::describe($user),
            'wallet_id' => $wallet?->uuid,
            'currency'  => $currency,
            'amount'    => $amount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function link(string $token): array
    {
        $link = PaymentLink::query()->where('token', $token)->with(['user', 'currency'])->first();

        if ($link === null) {
            return ['type' => 'unknown'];
        }

        $currency = $link->currencyCode();

        return [
            'type'       => 'link',
            'token'      => $link->token,
            'url'        => route('payment-link.show', ['token' => $link->token]),
            'title'      => $link->title,
            'amount'     => $link->amount === null ? null : ApiMoney::amount((string) $link->amount, $currency),
            'currency'   => $currency,
            'receiver'   => $link->user === null ? null : CounterpartyResolver::describe($link->user),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'active'     => $link->isActive() && ($link->expires_at === null || $link->expires_at->isFuture()),
        ];
    }

    /**
     * The till behind a counter QR's token, or behind an agent code typed
     * by hand - enabled and approved, as the cash-out endpoint requires, so
     * the app never names a counter that would refuse a moment later.
     *
     * `identifier` is what was scanned or typed, handed back for the cash-out
     * call; a code never turns into the QR token here.
     *
     * @return array<string, mixed>
     */
    private function agent(string $identifier): array
    {
        $agent = Agent::query()
            ->where('qr_enabled', true)
            ->where('status', AgentStatus::APPROVED)
            ->where(fn ($q) => $q->where('qr_token', $identifier)->orWhere('agent_code', $identifier))
            ->with('supportedCurrencies')
            ->first();

        if ($agent === null) {
            return ['type' => 'unknown'];
        }

        return [
            'type'       => 'agent',
            'identifier' => $identifier,
            'code'       => $agent->agent_code,
            'name'       => $agent->agent_name,
            'currencies' => $agent->supportedCurrencies->pluck('code')->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function merchant(string $reference): array
    {
        $checkout = Transaction::query()
            ->where('trx_id', $reference)
            ->whereIn('trx_type', [TrxType::RECEIVE_PAYMENT, TrxType::PAYMENT])
            ->first();

        if ($checkout === null || ! isset($checkout->trx_data['merchant_id'])) {
            return ['type' => 'unknown'];
        }

        return [
            'type'      => 'merchant',
            'reference' => $checkout->trx_id,
            'open'      => $checkout->status === TrxStatus::PENDING && ! $checkout->isExpired(),
        ];
    }

    /**
     * A checkout's signed payment page, as a counter QR now carries it so
     * any camera can open it: read only when this site signed it, and then
     * as the reference inside it. Anything else on the web stays unknown.
     *
     * @return array<string, mixed>
     */
    private function signedCheckout(string $url): array
    {
        $request = Request::create($url);

        if (! str_ends_with($request->path(), 'payment/checkout') || ! URL::hasValidSignature($request)) {
            return ['type' => 'unknown'];
        }

        try {
            $reference = Crypt::decryptString((string) $request->query('token'));
        } catch (DecryptException) {
            return ['type' => 'unknown'];
        }

        return $this->merchant($reference);
    }
}
