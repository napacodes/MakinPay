<?php

namespace App\Services\Payment\Instamojo;

use App\Models\PaymentGateway;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\CallbackSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Transaction;

class InstamojoPaymentGateway implements PaymentGatewayInterface
{
    protected $credentials;

    protected $baseUrl;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('instamojo');
        $this->baseUrl     = $this->credentials['sandbox']
            ? 'https://test.instamojo.com/api/1.1/'
            : 'https://www.instamojo.com/api/1.1/';
    }

    public function deposit($amount, $currency, $trxId)
    {
        // The transaction id rides in the purpose, which Instamojo returns on
        // every lookup, so the callback can find its way back even after the
        // cache entries below have expired.
        $paymentData = [
            'purpose'                 => 'Payment for '.setting('site_title').' #'.$trxId,
            'amount'                  => number_format($amount, 2, '.', ''),
            'phone'                   => $this->credentials['phone'] ?? '9999999999',
            'buyer_name'              => auth()->user()->name        ?? 'Customer',
            'redirect_url'            => route('ipn.handle', ['gateway' => 'instamojo']),
            'send_email'              => 'False',
            'webhook'                 => route('ipn.handle', ['gateway' => 'instamojo']),
            'send_sms'                => 'False',
            'email'                   => auth()->user()->email ?? 'customer@example.com',
            'allow_repeated_payments' => 'False',
        ];

        $response = Http::withHeaders([
            'X-Api-Key'    => $this->credentials['api_key'],
            'X-Auth-Token' => $this->credentials['auth_token'],
        ])->asForm()->post($this->baseUrl.'payment-requests/', $paymentData);

        if ($response->successful()) {
            $result = $response->json();

            if ($result['success']) {
                $requestId = (string) $result['payment_request']['id'];

                // Both directions: the callback only knows Instamojo's id.
                cache()->put('instamojo_request_'.$trxId, $requestId, 3600);
                cache()->put('instamojo_request_lookup_'.$requestId, $trxId, 3600);

                return $result['payment_request']['longurl'];
            }
        }

        throw new \Exception('Failed to create Instamojo payment request: '.$response->body());
    }

    /**
     * The callback names a payment request and a payment; both are looked up
     * at Instamojo with our credentials, and the transaction is whichever one
     * that payment request was created for — never a value from the body.
     * Before this, the lookup walked a cache method that does not exist, so
     * every Instamojo callback died with a 500 and no deposit ever settled.
     */
    public function handleIPN(Request $request): JsonResponse
    {
        $paymentId        = trim((string) $request->input('payment_id', ''));
        $paymentRequestId = trim((string) $request->input('payment_request_id', ''));

        if ($paymentId === '' || $paymentRequestId === '') {
            return response()->json(['error' => 'Missing parameters'], 400);
        }

        try {
            $verification = Http::withHeaders([
                'X-Api-Key'    => $this->credentials['api_key'],
                'X-Auth-Token' => $this->credentials['auth_token'],
            ])->get($this->baseUrl.'payment-requests/'.rawurlencode($paymentRequestId).'/'.rawurlencode($paymentId).'/');
        } catch (Throwable $e) {
            Log::error('Instamojo verification request failed: '.$e->getMessage(), ['payment_request_id' => $paymentRequestId]);

            return response()->json(['status' => 'unverified'], 502);
        }

        $body = $verification->json();

        if (! $verification->successful() || ! is_array($body)) {
            Log::warning('Instamojo verification unavailable', ['payment_request_id' => $paymentRequestId, 'http' => $verification->status()]);

            return response()->json(['status' => 'unverified'], 502);
        }

        if (empty($body['success'])) {
            return $this->refuse('unknown_payment', null, $paymentRequestId);
        }

        $paymentRequest = is_array($body['payment_request'] ?? null) ? $body['payment_request'] : [];
        $transactionId  = $this->transactionIdFor($paymentRequestId, (string) ($paymentRequest['purpose'] ?? ''));
        $transaction    = CallbackSettlement::find($transactionId);

        if ($transaction === null) {
            return $this->refuse('unknown_transaction', $transactionId, $paymentRequestId);
        }

        $payment = is_array($paymentRequest['payment'] ?? null) ? $paymentRequest['payment'] : [];
        $status  = (string) ($payment['status'] ?? '');

        if ($status === 'Failed') {
            Transaction::failTransaction($transactionId);

            return response()->json(['status' => 'failed']);
        }

        if ($status !== 'Credit') {
            return response()->json(['status' => 'pending']);
        }

        if (! CallbackSettlement::amountMatches($transaction, $payment['amount'] ?? null, $payment['currency'] ?? null)) {
            return $this->refuse('amount_mismatch', $transactionId, $paymentRequestId);
        }

        Transaction::completeTransaction($transactionId);
        Log::info('Instamojo payment completed for transaction: '.$transactionId);

        return response()->json(['status' => 'success']);
    }

    /**
     * The transaction a payment request was created for: the reverse cache
     * entry written at deposit, or the id carried in the purpose we set.
     */
    private function transactionIdFor(string $paymentRequestId, string $purpose): ?string
    {
        $cached = cache()->get('instamojo_request_lookup_'.$paymentRequestId);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (preg_match('/#([A-Za-z0-9_-]+)\s*$/', $purpose, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function refuse(string $reason, ?string $transactionId, string $paymentRequestId): JsonResponse
    {
        Log::warning('Instamojo IPN refused', array_filter([
            'reason'             => $reason,
            'trx_id'             => $transactionId,
            'payment_request_id' => $paymentRequestId,
        ]));

        return response()->json(['status' => 'rejected'], 422);
    }
}
