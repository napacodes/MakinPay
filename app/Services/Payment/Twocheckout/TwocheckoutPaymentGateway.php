<?php

namespace App\Services\Payment\Twocheckout;

use App\Models\PaymentGateway;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\CallbackSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Transaction;

/**
 * 2Checkout reaches this endpoint in three shapes, and each one is signed
 * with the account's secret word, so each is verified before a single field
 * of it is read:
 *
 * - the IPN of the current (Avangate) platform: `REFNOEXT` names our
 *   transaction, `HASH` is an HMAC-MD5 over every field (newer accounts add
 *   `SIGNATURE_SHA2_256` / `SIGNATURE_SHA3_256`), and 2Checkout expects an
 *   `<EPAYMENT>` acknowledgement or it keeps retrying;
 * - the legacy INS of the classic platform: `vendor_order_id` names our
 *   transaction and `md5_hash` covers sale, vendor and invoice;
 * - the buyer's own return from the hosted checkout (`return_url` points
 *   here too): `key` covers the order number and total.
 *
 * Before this, the hash check was computed with a made-up formula, logged
 * as failed and then ignored "for now", so a POST with `ORDERSTATUS=COMPLETE`
 * from anyone completed any pending deposit. The checkout URL is unsigned as
 * well, so the price in it is whatever the buyer's browser sent — which is
 * why every path also compares the paid total with what is owed.
 */
class TwocheckoutPaymentGateway implements PaymentGatewayInterface
{
    protected $credentials;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('twocheckout');
    }

    public function deposit($amount, $currency, $trxId)
    {
        // 2Checkout inline checkout parameters
        $checkoutUrl = 'https://secure.2checkout.com/checkout/purchase?'.http_build_query([
            'sid'                => $this->credentials['merchant_code'],
            'mode'               => '2CO',
            'li_0_type'          => 'product',
            'li_0_name'          => 'Payment for '.setting('site_title'),
            'li_0_price'         => number_format($amount, 2, '.', ''),
            'li_0_quantity'      => 1,
            'currency_code'      => $currency,
            'merchant_order_id'  => $trxId,
            'return_url'         => route('ipn.handle', ['gateway' => 'twocheckout']),
            'x_receipt_link_url' => route('home'),
            'demo'               => filter_var($this->credentials['sandbox'] ?? false, FILTER_VALIDATE_BOOL) ? 'Y' : 'N',
        ]);

        return $checkoutUrl;
    }

    public function handleIPN(Request $request): Response|JsonResponse|RedirectResponse
    {
        if ($request->filled('REFNOEXT') || $request->filled('HASH') || $request->filled('SIGNATURE_SHA2_256')) {
            return $this->handleInstantNotification($request);
        }

        if ($request->filled('md5_hash') && $request->filled('sale_id')) {
            return $this->handleLegacyNotification($request);
        }

        if ($request->filled('key') && $request->filled('order_number')) {
            return $this->handlePassback($request);
        }

        return $this->refuse('unrecognised_payload');
    }

    /**
     * The IPN of the current platform.
     */
    private function handleInstantNotification(Request $request): Response|JsonResponse
    {
        $params = $request->isMethod('post') ? $request->post() : $request->query();

        if (! $this->instantNotificationSigned($params)) {
            return $this->refuse('invalid_signature', (string) ($params['REFNOEXT'] ?? ''), 401);
        }

        $transactionId = trim((string) ($params['REFNOEXT'] ?? ''));
        $transaction   = CallbackSettlement::find($transactionId);

        if ($transaction === null) {
            return $this->refuse('unknown_transaction', $transactionId);
        }

        $orderStatus = strtoupper((string) ($params['ORDERSTATUS'] ?? ''));

        if (in_array($orderStatus, ['PAYMENT_AUTHORIZED', 'COMPLETE'], true)) {
            if (! CallbackSettlement::amountMatches($transaction, $params['IPN_TOTALGENERAL'] ?? null, $params['CURRENCY'] ?? null)) {
                return $this->refuse('amount_mismatch', $transactionId);
            }

            Transaction::completeTransaction($transactionId);
            Log::info('2Checkout payment completed for transaction: '.$transactionId.', Status: '.$orderStatus);
        } elseif (in_array($orderStatus, ['CANCELED', 'REVERSED'], true)) {
            Transaction::failTransaction($transactionId);
        } else {
            Log::info('2Checkout payment not completed for transaction: '.$transactionId.', Status: '.$orderStatus);
        }

        return $this->acknowledgeInstantNotification($params);
    }

    /**
     * The INS of the classic platform.
     */
    private function handleLegacyNotification(Request $request): Response|JsonResponse
    {
        $expected = strtoupper(md5(
            (string) $request->input('sale_id')
            .(string) ($this->credentials['merchant_code'] ?? '')
            .(string) $request->input('invoice_id')
            .$this->secret()
        ));

        $transactionId = trim((string) $request->input('vendor_order_id', ''));

        if ($this->secret() === '' || ! hash_equals($expected, strtoupper((string) $request->input('md5_hash')))) {
            return $this->refuse('invalid_signature', $transactionId, 401);
        }

        $transaction = CallbackSettlement::find($transactionId);

        if ($transaction === null) {
            return $this->refuse('unknown_transaction', $transactionId);
        }

        $invoiceStatus = strtolower((string) $request->input('invoice_status', ''));
        $fraudStatus   = strtolower((string) $request->input('fraud_status', ''));

        if (in_array($invoiceStatus, ['approved', 'deposited'], true) && $fraudStatus === 'pass') {
            if (! CallbackSettlement::amountMatches($transaction, $request->input('invoice_list_amount'), $request->input('list_currency'))) {
                return $this->refuse('amount_mismatch', $transactionId);
            }

            Transaction::completeTransaction($transactionId);
            Log::info('2Checkout payment completed for transaction: '.$transactionId.', Status: '.$invoiceStatus);
        } elseif ($invoiceStatus === 'declined' || $fraudStatus === 'fail') {
            Transaction::failTransaction($transactionId);
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * The buyer coming back from the hosted checkout. In demo mode 2Checkout
     * signs the key with an order number of 1 rather than the real one.
     */
    private function handlePassback(Request $request): RedirectResponse
    {
        $transactionId = trim((string) $request->input('merchant_order_id', ''));
        $total         = (string) $request->input('total', '');
        $demo          = filter_var($this->credentials['sandbox'] ?? false, FILTER_VALIDATE_BOOL);
        $orderNumber   = $demo ? '1' : (string) $request->input('order_number', '');
        $expected      = strtoupper(md5($this->secret().(string) ($this->credentials['merchant_code'] ?? '').$orderNumber.$total));
        $transaction   = CallbackSettlement::find($transactionId);

        if ($this->secret() === '' || $transaction === null || ! hash_equals($expected, strtoupper((string) $request->input('key')))) {
            Log::warning('2Checkout passback refused', ['reason' => 'invalid_key', 'trx_id' => $transactionId]);
            notifyEvs('warning', __('We could not verify this payment. If you were charged, please contact support.'));

            return redirect()->route('status.cancel', ['trx_id' => $transactionId]);
        }

        if ($request->input('credit_card_processed') === 'Y'
            && CallbackSettlement::amountMatches($transaction, $total, $request->input('currency_code'))) {
            Transaction::completeTransaction($transactionId);

            return redirect()->route('status.success', ['trx_id' => $transactionId]);
        }

        return redirect()->route('status.cancel', ['trx_id' => $transactionId]);
    }

    /**
     * Whether the IPN carries a signature we can reproduce. All of
     * 2Checkout's signatures are HMACs over the same string: every field in
     * the order received, each written as its byte length followed by the
     * value, and the signature fields themselves left out. Any one that
     * matches is enough — a forger can produce none of them.
     *
     * @param array<string, mixed> $params
     */
    private function instantNotificationSigned(array $params): bool
    {
        $secret = $this->secret();

        if ($secret === '') {
            return false;
        }

        $signed = $params;
        unset($signed['HASH'], $signed['SIGNATURE_SHA2_256'], $signed['SIGNATURE_SHA3_256']);

        $string = $this->serializeArray($signed);

        foreach (['SIGNATURE_SHA3_256' => 'sha3-256', 'SIGNATURE_SHA2_256' => 'sha256', 'HASH' => 'md5'] as $field => $algorithm) {
            $provided = strtolower(trim((string) ($params[$field] ?? '')));

            if ($provided !== '' && hash_equals(hash_hmac($algorithm, $string, $secret), $provided)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The receipt 2Checkout waits for. Without it the IPN is treated as
     * undelivered and resent.
     *
     * @param array<string, mixed> $params
     */
    private function acknowledgeInstantNotification(array $params): Response
    {
        $date   = now()->format('YmdHis');
        $string = '';

        foreach ([
            (string) data_get($params, 'IPN_PID.0', ''),
            (string) data_get($params, 'IPN_PNAME.0', ''),
            (string) ($params['IPN_DATE'] ?? ''),
            $date,
        ] as $piece) {
            $string .= strlen($piece).$piece;
        }

        $hash = hash_hmac('md5', $string, $this->secret());

        return response("<EPAYMENT>{$date}|{$hash}</EPAYMENT>", 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Serialize values the way 2Checkout signs them: each scalar as its byte
     * length followed by the value, an empty value as a single 0, arrays
     * flattened in order.
     */
    private function serializeArray($array): string
    {
        $retval = '';
        foreach ($array as $value) {
            if (is_array($value)) {
                $retval .= $this->serializeArray($value);
            } else {
                $valueStr = (string) $value;

                if ($valueStr === '') {
                    $retval .= '0';
                } else {
                    $retval .= strlen($valueStr).$valueStr;
                }
            }
        }

        return $retval;
    }

    private function secret(): string
    {
        $secret = (string) ($this->credentials['secret_key'] ?? '');

        return in_array($secret, ['', '...'], true) ? '' : $secret;
    }

    /**
     * Refuse without saying which check failed: the reason goes to the log
     * with safe identifiers, the body says nothing a prober can use.
     */
    private function refuse(string $reason, string $transactionId = '', int $status = 422): JsonResponse
    {
        Log::warning('2Checkout IPN refused', array_filter([
            'reason' => $reason,
            'trx_id' => $transactionId,
        ]));

        return response()->json(['status' => 'rejected'], $status);
    }
}
