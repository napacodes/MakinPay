<?php

namespace App\Services\Payment\MTN;

use App\Contracts\Payment\ConfirmsPushPayment;
use App\Enums\TrxStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\PaymentGateway;
use App\Models\Transaction as TransactionModel;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\CallbackSettlement;
use App\Support\CustomerPhone;
use App\Support\MobileMoneyPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Transaction;

class MTNPaymentGateway implements ConfirmsPushPayment, PaymentGatewayInterface
{
    /**
     * The country an MTN MoMo collection in this currency dials into. Only
     * currencies one MTN market owns; shared ones are left out on purpose.
     */
    private const array COUNTRY_BY_CURRENCY = [
        'GHS' => 'GH',
        'GNF' => 'GN',
        'LRD' => 'LR',
        'NGN' => 'NG',
        'RWF' => 'RW',
        'SZL' => 'SZ',
        'UGX' => 'UG',
        'ZAR' => 'ZA',
        'ZMW' => 'ZM',
    ];

    protected $credentials;

    protected $baseUrl;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('mtn');
        $this->baseUrl     = $this->credentials['sandbox']
            ? 'https://sandbox.momodeveloper.mtn.com'
            : 'https://momodeveloper.mtn.com';
    }

    public function deposit($amount, $currency, $trxId)
    {
        // Settled before anything is sent to MTN, so a payment with no number
        // is refused without spending a token request on it.
        $payerMsisdn = $this->payerMsisdn((string) $trxId, (string) $currency);

        // Get access token first
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            throw new \Exception('Failed to get MTN access token');
        }

        // Create payment request
        $requestToPay = [
            'amount'     => (string) $amount,
            'currency'   => strtoupper($currency),
            'externalId' => $trxId,
            'payer'      => [
                'partyIdType' => 'MSISDN',
                'partyId'     => $payerMsisdn,
            ],
            'payerMessage' => 'Payment for '.setting('site_title'),
            'payeeNote'    => 'Payment received from '.setting('site_title'),
        ];

        $referenceId = Str::uuid()->toString();

        $response = Http::withHeaders([
            'Authorization'             => 'Bearer '.$accessToken,
            'X-Reference-Id'            => $referenceId,
            'X-Target-Environment'      => $this->credentials['sandbox'] ? 'sandbox' : 'live',
            'Content-Type'              => 'application/json',
            'Ocp-Apim-Subscription-Key' => $this->credentials['subscription_key'],
        ])->post($this->baseUrl.'/collection/v1_0/requesttopay', $requestToPay);

        if ($response->successful() || $response->status() === 202) {
            // On the row as well as in the cache: the cache lasts an hour, and
            // a callback or a poll can arrive later than that.
            cache()->put('mtn_reference_'.$trxId, $referenceId, 3600);
            $this->rememberReference((string) $trxId, $referenceId);

            // Nothing redirects back from a handset, so the payer waits on a
            // page that asks MTN until the prompt is approved or declined.
            return MobileMoneyPrompt::waitingUrl((string) $trxId);
        }

        throw new \Exception('Failed to create MTN payment request: '.$response->body());
    }

    public function confirm(TransactionModel $transaction): TrxStatus
    {
        $referenceId = $this->storedReference($transaction);

        if ($transaction->status === TrxStatus::PENDING && $referenceId !== null) {
            $outcome = $this->settle($transaction, $referenceId);

            if (in_array($outcome, ['external_id_mismatch', 'amount_mismatch'], true)) {
                Log::warning('MTN confirmation refused', ['reason' => $outcome, 'trx_id' => $transaction->trx_id]);
            }
        }

        return $transaction->fresh()?->status ?? $transaction->status;
    }

    /**
     * MTN's callback carries no signature, so nothing in it is believed. The
     * reference only says which request-to-pay to ask MTN about; MTN's own
     * answer then has to name this transaction in `externalId`, report
     * SUCCESSFUL, and carry the amount the customer owes. Someone holding a
     * successful reference of their own cannot point it at another deposit.
     */
    public function handleIPN(Request $request): JsonResponse
    {
        $transactionId = trim((string) $request->input('transaction_id', ''));
        $referenceId   = trim((string) $request->input('reference_id', ''));

        if ($transactionId === '' || $referenceId === '') {
            return response()->json(['error' => 'Missing parameters'], 400);
        }

        $transaction = CallbackSettlement::find($transactionId);

        if ($transaction === null) {
            return $this->refuse('unknown_transaction', $transactionId);
        }

        $storedReference = $this->storedReference($transaction);

        if ($storedReference !== null && ! hash_equals($storedReference, $referenceId)) {
            return $this->refuse('reference_mismatch', $transactionId);
        }

        $outcome = $this->settle($transaction, $referenceId);

        return match ($outcome) {
            'success', 'failed', 'pending' => response()->json(['status' => $outcome]),
            'token_error' => response()->json(['error' => 'Token error'], 503),
            'unverified'  => response()->json(['status' => 'unverified'], 502),
            default       => $this->refuse($outcome, $transactionId),
        };
    }

    /**
     * Ask MTN about one request-to-pay and settle the transaction on its
     * answer. Shared by the callback and the waiting page's poll, so both are
     * held to the same proof: MTN's own record has to name this transaction
     * in `externalId`, and a success has to carry exactly the amount owed.
     *
     * @return string success | failed | pending | token_error | unverified | external_id_mismatch | amount_mismatch
     */
    private function settle(TransactionModel $transaction, string $referenceId): string
    {
        $transactionId = (string) $transaction->trx_id;
        $accessToken   = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('Failed to get MTN access token for verification', ['trx_id' => $transactionId]);

            return 'token_error';
        }

        try {
            $verification = Http::withHeaders([
                'Authorization'             => 'Bearer '.$accessToken,
                'X-Target-Environment'      => $this->credentials['sandbox'] ? 'sandbox' : 'live',
                'Ocp-Apim-Subscription-Key' => $this->credentials['subscription_key'],
            ])->get($this->baseUrl.'/collection/v1_0/requesttopay/'.rawurlencode($referenceId));
        } catch (Throwable $e) {
            Log::error('MTN verification request failed: '.$e->getMessage(), ['trx_id' => $transactionId]);

            return 'unverified';
        }

        $payment = $verification->json();

        if (! $verification->successful() || ! is_array($payment)) {
            Log::warning('MTN verification unavailable', ['trx_id' => $transactionId, 'http' => $verification->status()]);

            return 'unverified';
        }

        if ((string) ($payment['externalId'] ?? '') !== $transactionId) {
            return 'external_id_mismatch';
        }

        $status = strtoupper((string) ($payment['status'] ?? ''));

        // A declined prompt, a wrong PIN and a prompt left to expire all end
        // here, so the payer is told and the row does not stay pending.
        if ($status === 'FAILED') {
            Transaction::failTransaction($transactionId);

            return 'failed';
        }

        if ($status !== 'SUCCESSFUL') {
            return 'pending';
        }

        if (! CallbackSettlement::amountMatches($transaction, $payment['amount'] ?? null, $payment['currency'] ?? null)) {
            return 'amount_mismatch';
        }

        Transaction::completeTransaction($transactionId);
        Log::info('MTN payment completed for transaction: '.$transactionId);

        return 'success';
    }

    /**
     * The request-to-pay this transaction opened, from the row first and the
     * cache for a payment opened before the row kept it.
     */
    private function storedReference(TransactionModel $transaction): ?string
    {
        $reference = $transaction->trx_data['mtn_reference_id'] ?? cache()->get('mtn_reference_'.$transaction->trx_id);

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    private function rememberReference(string $trxId, string $referenceId): void
    {
        $transaction = TransactionModel::query()->where('trx_id', $trxId)->first();

        $transaction?->update([
            'trx_data' => array_merge($transaction->trx_data ?? [], ['mtn_reference_id' => $referenceId]),
        ]);
    }

    /**
     * The handset the request-to-pay is sent to: the payer's own number.
     *
     * This used to be the administrator's `test_msisdn` for every payment,
     * live included, so every customer's prompt went to one sandbox number
     * and no real collection could ever succeed. The test number is now only
     * a sandbox convenience for a payment that carries no number of its own.
     *
     * A local number is completed with the country of the currency being
     * collected; a currency several MTN countries share (XAF, XOF) has no one
     * country, so there the payer has to type the number with its code.
     *
     * @throws NotifyErrorException
     */
    private function payerMsisdn(string $trxId, string $currency): string
    {
        $typed = TransactionModel::query()->where('trx_id', $trxId)->first()?->trx_data['customer_phone'] ?? null;

        if (is_string($typed) && $typed !== '') {
            $msisdn = CustomerPhone::international($typed, self::COUNTRY_BY_CURRENCY[strtoupper($currency)] ?? null);

            if ($msisdn === null) {
                throw new NotifyErrorException(__('Enter your mobile money number with its country code, for example +256 77 1234567.'));
            }

            return $msisdn;
        }

        $testMsisdn = CustomerPhone::international($this->credentials['test_msisdn'] ?? null, null);

        if ($this->credentials['sandbox'] && $testMsisdn !== null) {
            return $testMsisdn;
        }

        throw new NotifyErrorException(__('Enter the mobile money number the payment should be requested from.'));
    }

    private function refuse(string $reason, string $transactionId): JsonResponse
    {
        Log::warning('MTN IPN refused', ['reason' => $reason, 'trx_id' => $transactionId]);

        return response()->json(['status' => 'rejected'], 422);
    }

    private function getAccessToken(): ?string
    {
        // Check cache first
        $cacheKey = 'mtn_access_token';
        $token    = cache()->get($cacheKey);

        if ($token) {
            return $token;
        }

        // Get new token
        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->credentials['subscription_key'],
            'Authorization'             => 'Basic '.base64_encode($this->credentials['user_id'].':'.$this->credentials['api_key']),
        ])->post($this->baseUrl.'/collection/token/');

        if ($response->successful()) {
            $data      = $response->json();
            $token     = $data['access_token'];
            $expiresIn = $data['expires_in'] ?? 3600;

            // Cache token for slightly less than expiry time
            cache()->put($cacheKey, $token, $expiresIn - 300);

            return $token;
        }

        return null;
    }
}
