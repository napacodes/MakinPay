<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Contracts\Payment\ConfirmsPushPayment;
use App\Enums\TrxStatus;
use App\Exceptions\GatewayBindingViolationException;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Payment\PaymentGatewayFactory;
use App\Support\GatewayCallbackContext;
use App\Support\MobileMoneyPrompt;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The "approve on your phone" page for MTN MoMo and Airtel Money.
 *
 * Nothing redirects back from a handset: once the prompt is sent the payer
 * approves with their PIN and the provider records it. The page polls this
 * controller, and this controller asks the provider - never the browser -
 * whether the payment went through.
 *
 * Both routes are reached only through a signed link the gateway issued for
 * this one transaction, which is what stands in for ownership: the payer may
 * be a guest with no account to check against.
 */
class MobileMoneyPaymentController extends Controller
{
    /**
     * At most one question to the provider per payment in this many seconds,
     * however many tabs are polling.
     */
    private const int CONFIRM_EVERY_SECONDS = 8;

    public function __construct(private readonly PaymentGatewayFactory $gateways) {}

    public function wait(string $trx): View
    {
        [$transaction] = $this->pushPayment($trx);

        return view('general.mobile_money.wait', [
            'transaction' => $transaction,
            'maskedPhone' => MobileMoneyPrompt::maskedPhone($transaction->trx_data['customer_phone'] ?? null),
            'statusUrl'   => MobileMoneyPrompt::statusUrl((string) $transaction->trx_id),
            'laterUrl'    => $this->finishedUrl($transaction),
        ]);
    }

    public function status(string $trx): JsonResponse
    {
        [$transaction, $gateway, $gatewayCode] = $this->pushPayment($trx);

        $status = $transaction->status;

        if ($status === TrxStatus::PENDING && Cache::add('mobile-money-confirm:'.$transaction->trx_id, true, self::CONFIRM_EVERY_SECONDS)) {
            $status = $this->confirm($transaction, $gateway, $gatewayCode);
        }

        $final = $status !== TrxStatus::PENDING;

        return response()->json([
            'status'   => $status->value,
            'final'    => $final,
            'redirect' => $final ? $this->finishedUrl($transaction->fresh() ?? $transaction) : null,
        ]);
    }

    /**
     * Settled under the same gateway binding as a callback from that
     * gateway, so the poll can only ever settle the payment it opened.
     */
    private function confirm(Transaction $transaction, ConfirmsPushPayment $gateway, string $gatewayCode): TrxStatus
    {
        app(GatewayCallbackContext::class)->initialize($gatewayCode);

        try {
            return $gateway->confirm($transaction);
        } catch (GatewayBindingViolationException) {
            Log::warning('Mobile money confirmation refused by the gateway binding', [
                'trx_id'  => $transaction->trx_id,
                'gateway' => $gatewayCode,
            ]);
        } catch (Throwable $e) {
            // The provider being unreachable is not the payer's fault; the
            // page keeps waiting and the callback can still settle it.
            Log::warning('Mobile money confirmation failed', [
                'trx_id'  => $transaction->trx_id,
                'gateway' => $gatewayCode,
                'error'   => $e->getMessage(),
            ]);
        }

        return $transaction->fresh()?->status ?? $transaction->status;
    }

    /**
     * @return array{0: Transaction, 1: ConfirmsPushPayment, 2: string}
     */
    private function pushPayment(string $trx): array
    {
        $transaction = Transaction::query()->where('trx_id', $trx)->firstOrFail();
        $gatewayCode = (string) $transaction->gateway_code;

        try {
            $gateway = $gatewayCode !== '' ? $this->gateways->getGateway($gatewayCode, requireActive: false) : null;
        } catch (Throwable) {
            $gateway = null;
        }

        abort_unless($gateway instanceof ConfirmsPushPayment, 404);

        return [$transaction, $gateway, $gatewayCode];
    }

    /**
     * Where the payer goes once the payment has an answer, or if they stop
     * waiting: the payment link's own pages for a link, the merchant's cancel
     * address for a refused merchant checkout, and otherwise the shared
     * status handler every asynchronous gateway already returns to.
     */
    private function finishedUrl(Transaction $transaction): string
    {
        $data   = $transaction->trx_data ?? [];
        $failed = in_array($transaction->status, [TrxStatus::FAILED, TrxStatus::CANCELED], true);

        if (($data['source'] ?? null) === 'payment_link' && is_string($data['token'] ?? null)) {
            return $transaction->status === TrxStatus::COMPLETED
                ? route('payment-link.success', $data['token'])
                : route('payment-link.show', $data['token']);
        }

        if ($failed && filter_var($data['cancel_redirect'] ?? null, FILTER_VALIDATE_URL)) {
            return (string) $data['cancel_redirect'];
        }

        return route('status.pending', ['trx_id' => $transaction->trx_id]);
    }
}
