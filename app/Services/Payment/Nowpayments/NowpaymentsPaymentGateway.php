<?php

namespace App\Services\Payment\Nowpayments;

use App\Models\PaymentGateway;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Transaction;

class NowpaymentsPaymentGateway implements PaymentGatewayInterface
{
    protected $credentials;

    protected $baseUrl;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('nowpayments');
        $this->baseUrl     = $this->credentials['sandbox'] ? 'https://api-sandbox.nowpayments.io' : 'https://api.nowpayments.io';
    }

    public function deposit($amount, $currency, $trxId)
    {
        // Create payment
        $paymentData = [
            'price_amount'      => (float) $amount,
            'price_currency'    => strtoupper($currency),
            'pay_currency'      => strtoupper($this->credentials['pay_currency'] ?? 'BTC'),
            'ipn_callback_url'  => route('ipn.handle', ['gateway' => 'nowpayments']),
            'order_id'          => $trxId,
            'order_description' => 'Payment for '.setting('site_title'),
            'success_url'       => route('status.success', ['trx_id' => $trxId]),
            'cancel_url'        => route('status.cancel', ['trx_id' => $trxId]),
        ];

        $response = Http::withHeaders([
            'x-api-key'    => $this->credentials['api_key'],
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl.'/v1/payment', $paymentData);

        if ($response->successful()) {
            $payment = $response->json();

            return $payment['invoice_url'] ?? $payment['payment_url'];
        }

        throw new \Exception('Failed to create NOWPayments payment: '.$response->body());
    }

    public function handleIPN(Request $request): JsonResponse
    {
        Log::info('NOWPayments IPN received', $request->all());

        try {
            // Verify IPN signature
            $receivedSignature = $request->header('x-nowpayments-sig');
            $payload           = $request->getContent();

            if (! $this->verifySignature($payload, $receivedSignature)) {
                Log::error('NOWPayments signature verification failed');

                return response()->json(['error' => 'Invalid signature'], 400);
            }

            $data          = $request->all();
            $paymentStatus = $data['payment_status'] ?? '';
            $orderId       = $data['order_id']       ?? '';

            if (! $orderId) {
                Log::error('NOWPayments IPN missing order_id');

                return response()->json(['error' => 'Missing order ID'], 400);
            }

            if ($paymentStatus === 'finished') {
                // Fully settled — credit the wallet.
                Transaction::completeTransaction($orderId, 'NOWPayments: finished');
                Log::info('NOWPayments payment completed for transaction: '.$orderId);
            } elseif ($paymentStatus === 'partially_paid') {
                // Underpayment: the buyer sent less than the invoice. Never
                // credit the full amount — record what arrived and leave the
                // transaction pending for admin review.
                $this->recordUnderpayment($orderId, $data);
            } elseif (in_array($paymentStatus, ['failed', 'expired', 'refunded'], true)) {
                Transaction::failTransaction($orderId, 'NOWPayments: '.$paymentStatus);
                Log::info('NOWPayments payment '.$paymentStatus.' for transaction: '.$orderId);
            } else {
                // waiting / confirming / confirmed / sending — acknowledge and
                // keep the transaction pending until a terminal status lands.
                Log::info('NOWPayments payment status: '.$paymentStatus.' for transaction: '.$orderId);
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('NOWPayments IPN error: '.$e->getMessage());

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Persist the details of an underpaid NOWPayments invoice and leave the
     * transaction pending so an admin can decide how to settle it.
     *
     * @param array<string, mixed> $data
     */
    private function recordUnderpayment(string $orderId, array $data): void
    {
        $actuallyPaid = (float) ($data['actually_paid'] ?? 0);
        $payAmount    = (float) ($data['pay_amount'] ?? 0);

        Log::warning('NOWPayments partial payment left pending for review', [
            'order_id'      => $orderId,
            'actually_paid' => $actuallyPaid,
            'pay_amount'    => $payAmount,
        ]);

        $transaction = Transaction::findTransaction($orderId);

        if (! $transaction) {
            return;
        }

        $transaction->update([
            'trx_data' => array_merge($transaction->trx_data ?? [], [
                'nowpayments_underpayment' => [
                    'actually_paid' => $actuallyPaid,
                    'pay_amount'    => $payAmount,
                    'pay_currency'  => $data['pay_currency'] ?? null,
                    'payment_id'    => $data['payment_id']   ?? null,
                ],
            ]),
            'remarks' => __('Partial NOWPayments payment received (:paid / :due :currency). Pending manual review.', [
                'paid'     => $actuallyPaid,
                'due'      => $payAmount,
                'currency' => strtoupper((string) ($data['pay_currency'] ?? '')),
            ]),
        ]);
    }

    private function verifySignature($payload, $signature): bool
    {
        if (! isset($this->credentials['ipn_secret']) || ! $signature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha512', $payload, $this->credentials['ipn_secret']);

        return hash_equals($expectedSignature, $signature);
    }
}
