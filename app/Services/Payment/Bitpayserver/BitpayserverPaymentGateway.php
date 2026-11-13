<?php

namespace App\Services\Payment\Bitpayserver;

use App\Models\PaymentGateway;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\CallbackSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Transaction;

class BitpayserverPaymentGateway implements PaymentGatewayInterface
{
    /**
     * Invoice states, across BTCPay's legacy and Greenfield shapes, in which
     * the payment has the confirmations the store demands. `paid` and
     * `processing` mean seen on the network but not yet confirmed, and a
     * deposit credited at that point can still be double-spent away.
     */
    private const SETTLED_STATES = ['confirmed', 'complete', 'settled'];

    private const FAILED_STATES = ['expired', 'invalid'];

    protected $credentials;

    protected $baseUrl;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('bitpayserver');
        $this->baseUrl     = rtrim($this->credentials['server_url'], '/');
    }

    public function deposit($amount, $currency, $trxId)
    {
        // Create BTCPay Server invoice
        $invoiceData = [
            'amount'          => (float) $amount,
            'currency'        => strtoupper($currency),
            'orderId'         => $trxId,
            'notificationURL' => route('ipn.handle', ['gateway' => 'bitpayserver']),
            'redirectURL'     => route('status.success', ['trx_id' => $trxId]),
            'itemDesc'        => 'Payment for '.setting('site_title'),
            'itemCode'        => $trxId,
            'physical'        => false,
            'buyer'           => [
                'name'  => auth()->user()->name  ?? 'Customer',
                'email' => auth()->user()->email ?? 'customer@example.com',
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'token '.$this->credentials['api_token'],
            'Content-Type'  => 'application/json',
        ])->post($this->baseUrl.'/api/v1/invoices', $invoiceData);

        if ($response->successful()) {
            $invoice = $response->json();

            return $invoice['url'];
        }

        throw new \Exception('Failed to create BTCPay Server invoice: '.$response->body());
    }

    /**
     * The notification is read for one thing: which invoice to ask BTCPay
     * about. Which of our transactions that invoice was for is BTCPay's
     * answer (`orderId`, set at creation), as are its state and amount.
     * Before this, the invoice was verified but the transaction completed
     * was the one named in the body, so a settled invoice of any size could
     * be pointed at any pending deposit.
     */
    public function handleIPN(Request $request): JsonResponse
    {
        $invoiceId = trim((string) ($request->input('id') ?: $request->input('invoiceId', '')));

        if ($invoiceId === '') {
            return response()->json(['error' => 'Missing invoice ID'], 400);
        }

        try {
            $verification = Http::withHeaders([
                'Authorization' => 'token '.$this->credentials['api_token'],
            ])->get($this->baseUrl.'/api/v1/invoices/'.rawurlencode($invoiceId));
        } catch (Throwable $e) {
            Log::error('BTCPay Server verification request failed: '.$e->getMessage(), ['invoice_id' => $invoiceId]);

            return response()->json(['status' => 'unverified'], 502);
        }

        $invoice = $verification->json();

        if (is_array($invoice) && is_array($invoice['data'] ?? null)) {
            $invoice = $invoice['data'];
        }

        if (! $verification->successful() || ! is_array($invoice)) {
            Log::warning('BTCPay Server verification unavailable', ['invoice_id' => $invoiceId, 'http' => $verification->status()]);

            return response()->json(['status' => 'unverified'], 502);
        }

        $orderId     = trim((string) (data_get($invoice, 'metadata.orderId') ?: data_get($invoice, 'orderId', '')));
        $transaction = CallbackSettlement::find($orderId);

        if ($transaction === null) {
            return $this->refuse('unknown_transaction', $orderId, $invoiceId);
        }

        $status = strtolower((string) ($invoice['status'] ?? ''));

        if (in_array($status, self::FAILED_STATES, true)) {
            Transaction::failTransaction($orderId);

            return response()->json(['status' => $status]);
        }

        if (! in_array($status, self::SETTLED_STATES, true)) {
            Log::info('BTCPay Server payment status: '.$status.' for transaction: '.$orderId);

            return response()->json(['status' => 'pending']);
        }

        $amount   = data_get($invoice, 'amount', data_get($invoice, 'price'));
        $currency = data_get($invoice, 'currency');

        if (! CallbackSettlement::amountMatches($transaction, $amount, $currency)) {
            return $this->refuse('amount_mismatch', $orderId, $invoiceId);
        }

        Transaction::completeTransaction($orderId);
        Log::info('BTCPay Server payment completed for transaction: '.$orderId);

        return response()->json(['status' => 'success']);
    }

    private function refuse(string $reason, string $orderId, string $invoiceId): JsonResponse
    {
        Log::warning('BTCPay Server IPN refused', array_filter([
            'reason'     => $reason,
            'trx_id'     => $orderId,
            'invoice_id' => $invoiceId,
        ]));

        return response()->json(['status' => 'rejected'], 422);
    }
}
