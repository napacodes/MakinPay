<?php

namespace App\Services\Payment\Coingate;

use App\Models\PaymentGateway;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\CallbackSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Transaction;

class CoingatePaymentGateway implements PaymentGatewayInterface
{
    protected $credentials;

    protected $baseUrl;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('coingate');
        $this->baseUrl     = $this->credentials['sandbox']
            ? 'https://api-sandbox.coingate.com'
            : 'https://api.coingate.com';
    }

    public function deposit($amount, $currency, $trxId)
    {
        // Create CoinGate order
        $orderData = [
            'order_id'         => $trxId,
            'price_amount'     => (float) $amount,
            'price_currency'   => strtoupper($currency),
            'receive_currency' => strtoupper($this->credentials['receive_currency'] ?? 'EUR'),
            'callback_url'     => route('ipn.handle', ['gateway' => 'coingate']),
            'cancel_url'       => route('status.cancel', ['trx_id' => $trxId]),
            'success_url'      => route('status.success', ['trx_id' => $trxId]),
            'title'            => 'Payment for '.setting('site_title'),
            'description'      => 'Payment transaction #'.$trxId,
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Token '.$this->credentials['auth_token'],
            'Content-Type'  => 'application/json',
        ])->post($this->baseUrl.'/v2/orders', $orderData);

        if ($response->successful()) {
            $order = $response->json();

            return $order['payment_url'];
        }

        throw new \Exception('Failed to create CoinGate order: '.$response->body());
    }

    /**
     * CoinGate's callback is unsigned. It carries CoinGate's own order id
     * (`id`) and ours (`order_id`); the first is looked up at CoinGate, and
     * what CoinGate returns has to name our order, be paid, and carry the
     * price the customer owes. Before this, the lookup used our id — which
     * CoinGate does not key orders by — so the whole check was a no-op.
     */
    public function handleIPN(Request $request): JsonResponse
    {
        $coingateId = trim((string) $request->input('id', ''));
        $orderId    = trim((string) $request->input('order_id', ''));

        if ($coingateId === '' || $orderId === '') {
            return response()->json(['error' => 'Missing order ID'], 400);
        }

        $transaction = CallbackSettlement::find($orderId);

        if ($transaction === null) {
            return $this->refuse('unknown_transaction', $orderId);
        }

        try {
            $verification = Http::withHeaders([
                'Authorization' => 'Token '.$this->credentials['auth_token'],
            ])->get($this->baseUrl.'/v2/orders/'.rawurlencode($coingateId));
        } catch (Throwable $e) {
            Log::error('CoinGate verification request failed: '.$e->getMessage(), ['trx_id' => $orderId]);

            return response()->json(['status' => 'unverified'], 502);
        }

        $order = $verification->json();

        if (! $verification->successful() || ! is_array($order)) {
            Log::warning('CoinGate verification unavailable', ['trx_id' => $orderId, 'http' => $verification->status()]);

            return response()->json(['status' => 'unverified'], 502);
        }

        if ((string) ($order['order_id'] ?? '') !== $orderId) {
            return $this->refuse('order_mismatch', $orderId);
        }

        $status = strtolower((string) ($order['status'] ?? ''));

        if (in_array($status, ['invalid', 'expired', 'canceled'], true)) {
            Transaction::failTransaction($orderId);

            return response()->json(['status' => $status]);
        }

        if ($status !== 'paid') {
            Log::info('CoinGate payment status: '.$status.' for transaction: '.$orderId);

            return response()->json(['status' => 'pending']);
        }

        if (! CallbackSettlement::amountMatches($transaction, $order['price_amount'] ?? null, $order['price_currency'] ?? null)) {
            return $this->refuse('amount_mismatch', $orderId);
        }

        Transaction::completeTransaction($orderId);
        Log::info('CoinGate payment completed for transaction: '.$orderId);

        return response()->json(['status' => 'success']);
    }

    private function refuse(string $reason, string $orderId): JsonResponse
    {
        Log::warning('CoinGate IPN refused', ['reason' => $reason, 'trx_id' => $orderId]);

        return response()->json(['status' => 'rejected'], 422);
    }
}
