<?php

declare(strict_types=1);

namespace App\Services\Payment\Cashmaal;

use App\Enums\TrxStatus;
use App\Models\PaymentGateway;
use App\Models\Transaction as TransactionModel;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\DecimalMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;
use Transaction;

/**
 * CashMaal, against the provider's current documented contract.
 *
 * What was here before matched no version of CashMaal's API: it sent `web`,
 * `custom`, `reference`, `item_name` and `ipn_url` on a GET query string, and
 * settled on `payment_status` read straight out of an unauthenticated POST.
 * Anyone who could reach /ipn/cashmaal could complete their own pending
 * deposit without paying for it.
 *
 * Settlement now has to survive, in order:
 *
 *   1  ipn_key matches the configured merchant key (hash_equals)
 *   2  web_id matches the configured merchant
 *   3  status is "1" — the only documented success
 *   4  order_id resolves to one CashMaal-bound transaction
 *   5  CM_TID is present and has not settled another transaction
 *   6  currency and Amount match the transaction
 *   7  verify_v2 independently confirms the payment, its order and its amount
 *
 * Any doubt at any step means no credit and no acknowledgement. CashMaal
 * retries until it sees `**OK**`, so a refusal it should retry costs nothing,
 * while an acknowledgement we cannot justify costs money.
 */
class CashmaalPaymentGateway implements PaymentGatewayInterface
{
    /** The documented checkout endpoint. POST, not GET. */
    public const CHECKOUT_URL = 'https://cmaal.com/Pay/';

    /** The documented server-to-server verification endpoint. */
    public const VERIFY_URL = 'https://api.cmaal.com/verify_v2';

    /** The documented acknowledgement. CashMaal retries until it sees this. */
    private const ACK = '**OK**';

    /** The only currencies the Receive Money API accepts. */
    private const SUPPORTED_CURRENCIES = ['USD', 'PKR'];

    /** @var array<string, mixed> */
    protected array $credentials;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('cashmaal');
    }

    /**
     * The documented checkout is a POST, and `deposit()` returns a URL.
     *
     * Rather than change the contract every other gateway shares, this returns
     * a signed link to a page that renders the documented fields and submits
     * them. Signed because that page carries the payer's email and the amount.
     */
    public function deposit($amount, $currency, $trxId): string
    {
        $currency = strtoupper((string) $currency);

        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new \Exception(sprintf('CashMaal does not support %s.', $currency));
        }

        return URL::signedRoute('gateway.cashmaal.redirect', ['trx_id' => $trxId]);
    }

    /**
     * The documented request parameters, `succes_url` spelled as CashMaal
     * spells it. No credential belongs here: `web_id` is a public merchant
     * identifier, and the IPN key never reaches the browser.
     *
     * @return array<string, string>
     */
    public function checkoutFields(TransactionModel $transaction): array
    {
        return [
            'web_id'       => (string) ($this->credentials['web_id'] ?? ''),
            'amount'       => (string) $transaction->payable_amount,
            'currency'     => strtoupper((string) $transaction->payable_currency),
            'succes_url'   => route('status.success', ['trx_id' => $transaction->trx_id]),
            'cancel_url'   => route('status.cancel', ['trx_id' => $transaction->trx_id]),
            'client_email' => (string) ($transaction->user->email ?? ''),
            'order_id'     => (string) $transaction->trx_id,
            'addi_info'    => (string) setting('site_title'),
        ];
    }

    /**
     * The documented callback.
     *
     * Returns the acknowledgement only when the payment has been
     * authenticated, bound to a local transaction, and independently confirmed
     * by CashMaal.
     */
    public function handleIPN(Request $request): Response
    {
        try {
            return $this->settle($request);
        } catch (Throwable $e) {
            // Never the payload, never the key.
            Log::error('CashMaal IPN failed', ['reason' => 'unhandled_error']);
            report($e);

            return $this->refuse();
        }
    }

    private function settle(Request $request): Response
    {
        $configuredKey   = trim((string) ($this->credentials['ipn_key'] ?? ''));
        $configuredWebId = trim((string) ($this->credentials['web_id'] ?? ''));
        $callbackKey     = (string) $request->input('ipn_key', '');

        // 1. Authentication. An installation that has not configured its key
        //    settles nothing — there is no default and no grandfathering.
        if ($configuredKey === '' || $callbackKey === '' || ! hash_equals($configuredKey, $callbackKey)) {
            return $this->refuse('authentication_failed');
        }

        // 2. Merchant identity, checked independently of the key.
        if ($configuredWebId === '' || ! hash_equals($configuredWebId, (string) $request->input('web_id', ''))) {
            return $this->refuse('web_id_mismatch');
        }

        // 3. Only the documented success status is worth pursuing. Pending,
        //    rejected and cancelled are CashMaal's record, not a mutation here.
        if ((string) $request->input('status') !== '1') {
            return $this->refuse('status_not_successful');
        }

        $orderId = trim((string) $request->input('order_id', ''));
        $cmTid   = trim((string) $request->input('CM_TID', ''));

        if ($orderId === '' || $cmTid === '') {
            return $this->refuse('missing_identifiers');
        }

        // 4. The local transaction, found by the documented order_id — never
        //    by the legacy custom/reference fields.
        $transaction = TransactionModel::query()
            ->where('trx_id', $orderId)
            ->where('gateway_code', 'cashmaal')
            ->first();

        if (! $transaction) {
            return $this->refuse('unknown_order', $orderId, $cmTid);
        }

        // 5. One CashMaal payment settles one DigiKash transaction.
        if ($this->referenceSpentElsewhere($cmTid, $transaction)) {
            return $this->refuse('provider_reference_reused', $orderId, $cmTid);
        }

        // A retry of a payment already settled is acknowledged, not repeated.
        if ($transaction->status === TrxStatus::COMPLETED) {
            return hash_equals((string) $transaction->trx_reference, $cmTid)
                ? $this->acknowledge()
                : $this->refuse('provider_reference_reused', $orderId, $cmTid);
        }

        // 6. What the callback claims must match what we are owed, before we
        //    spend a request asking the provider about it.
        $currency = strtoupper((string) $request->input('currency', ''));

        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true)
            || $currency !== strtoupper((string) $transaction->payable_currency)) {
            return $this->refuse('currency_mismatch', $orderId, $cmTid);
        }

        if (! $this->amountMatches($request->input('Amount'), $transaction)) {
            return $this->refuse('callback_amount_mismatch', $orderId, $cmTid);
        }

        // 7. The provider's own word, asked for with our own web_id.
        $verified = $this->verify($cmTid, $configuredWebId);

        if ($verified === null) {
            return $this->refuse('verification_unavailable', $orderId, $cmTid);
        }

        if ((string) ($verified['status'] ?? '') !== '1') {
            return $this->refuse('verification_unsuccessful', $orderId, $cmTid);
        }

        if (! hash_equals((string) ($verified['order_id'] ?? ''), $orderId)) {
            return $this->refuse('verification_order_mismatch', $orderId, $cmTid);
        }

        // The base amount, never *_amount_with_fee: that fee is CashMaal's own
        // and its semantics are not yet proven from a real transaction.
        $verifiedAmount = $verified[$currency === 'PKR' ? 'PKR_amount' : 'USD_amount'] ?? null;

        if (! $this->amountMatches($verifiedAmount, $transaction)) {
            return $this->refuse('verified_amount_mismatch', $orderId, $cmTid);
        }

        $transaction->forceFill(['trx_reference' => $cmTid])->save();

        Transaction::completeTransaction($transaction->trx_id, 'CashMaal payment verified.');

        Log::info('CashMaal payment settled', [
            'trx_id' => $transaction->trx_id,
            'CM_TID' => $cmTid,
            'result' => 'settled',
        ]);

        return $this->acknowledge();
    }

    /**
     * Whether this CashMaal payment has already settled a different one of our
     * transactions.
     *
     * Enforced here rather than with a unique index: `trx_reference` is
     * deliberately reused by REFUND rows across several services, so a
     * database constraint would break refunds.
     */
    private function referenceSpentElsewhere(string $cmTid, TransactionModel $transaction): bool
    {
        return TransactionModel::query()
            ->where('gateway_code', 'cashmaal')
            ->where('trx_reference', $cmTid)
            ->whereKeyNot($transaction->getKey())
            ->exists();
    }

    /**
     * Decimal comparison against what the customer actually owes. Never a
     * float: an amount is not something to compare approximately.
     */
    private function amountMatches(mixed $amount, TransactionModel $transaction): bool
    {
        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return false;
        }

        return DecimalMoney::compare((string) $amount, (string) $transaction->payable_amount) === 0;
    }

    /**
     * Ask CashMaal what really happened.
     *
     * `web_id` comes from configuration, never from the callback: letting the
     * request choose which merchant account is queried would hand an attacker
     * the answer as well as the question.
     *
     * Returns null for anything that is not a conclusive payload — a timeout,
     * a 5xx, a body that is not JSON, a missing status. Not verified is not the
     * same as not paid, and neither one settles.
     *
     * @return array<string, mixed>|null
     */
    private function verify(string $cmTid, string $webId): ?array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->get(self::VERIFY_URL, [
                    'CM_TID' => $cmTid,
                    'web_id' => $webId,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('status', $body)) {
            return null;
        }

        return $body;
    }

    private function acknowledge(): Response
    {
        return response(self::ACK, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Refuse without telling the caller why.
     *
     * The reason goes to the log with safe identifiers only; the body says
     * nothing an attacker could use to probe which check failed.
     */
    private function refuse(?string $reason = null, ?string $orderId = null, ?string $cmTid = null): Response
    {
        if ($reason !== null) {
            Log::warning('CashMaal IPN refused', array_filter([
                'reason' => $reason,
                'trx_id' => $orderId,
                'CM_TID' => $cmTid,
            ]));
        }

        return response('REJECTED', 422)->header('Content-Type', 'text/plain');
    }
}
