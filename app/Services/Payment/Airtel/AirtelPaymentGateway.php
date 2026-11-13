<?php

namespace App\Services\Payment\Airtel;

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
use Throwable;
use Transaction;

class AirtelPaymentGateway implements ConfirmsPushPayment, PaymentGatewayInterface
{
    protected $credentials;

    protected $baseUrl;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('airtel');
        $this->baseUrl     = $this->credentials['sandbox']
            ? 'https://openapiuat.airtel.africa'
            : 'https://openapi.airtel.africa';
    }

    public function deposit($amount, $currency, $trxId)
    {
        // Settled before anything is sent to Airtel, so a payment with no
        // number is refused without spending a token request on it.
        $payerMsisdn = $this->payerMsisdn((string) $trxId);

        // Get access token first
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            throw new \Exception('Failed to get Airtel access token');
        }

        // Initiate payment collection
        $collectionData = [
            'reference'  => $trxId,
            'subscriber' => [
                'country'  => strtoupper($this->credentials['country']),
                'currency' => strtoupper($currency),
                'msisdn'   => $payerMsisdn,
            ],
            'transaction' => [
                'amount'   => (float) $amount,
                'country'  => strtoupper($this->credentials['country']),
                'currency' => strtoupper($currency),
                'id'       => $trxId,
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$accessToken,
            'Content-Type'  => 'application/json',
            'X-Country'     => strtoupper($this->credentials['country']),
            'X-Currency'    => strtoupper($currency),
        ])->post($this->baseUrl.'/merchant/v1/payments/', $collectionData);

        $result = $response->json();

        /*
         * Airtel answers in an envelope whose `status` is an object, and it
         * reports a refused collection with HTTP 200 and `status.success`
         * false. This compared `status` with the string 'TXN_201', which an
         * object never equals, so every Airtel payment was refused here even
         * when Airtel had already sent the prompt to the payer's phone.
         */
        if ($response->successful() && is_array($result) && $this->envelopeSucceeded($result)) {
            $airtelId = (string) (data_get($result, 'data.transaction.id') ?: $trxId);

            cache()->put('airtel_transaction_'.$trxId, $airtelId, 3600);
            $this->rememberReference((string) $trxId, $airtelId);

            // Nothing redirects back from a handset, so the payer waits on a
            // page that asks Airtel until the prompt is approved or declined.
            return MobileMoneyPrompt::waitingUrl((string) $trxId);
        }

        Log::warning('Airtel collection was not accepted', [
            'trx_id'      => $trxId,
            'http'        => $response->status(),
            'result_code' => data_get($result, 'status.result_code'),
        ]);

        throw new \Exception(__('Airtel Money could not start this payment. Please try again.'));
    }

    public function confirm(TransactionModel $transaction): TrxStatus
    {
        if ($transaction->status === TrxStatus::PENDING) {
            $outcome = $this->settle($transaction);

            if (in_array($outcome, ['reference_mismatch', 'amount_mismatch'], true)) {
                Log::warning('Airtel confirmation refused', ['reason' => $outcome, 'trx_id' => $transaction->trx_id]);
            }
        }

        return $transaction->fresh()?->status ?? $transaction->status;
    }

    /**
     * Ask Airtel about one collection and settle the transaction on its
     * answer.
     *
     * The enquiry is keyed by the id this site gave the collection, under this
     * site's own credentials, so a TS answer is Airtel saying that the
     * collection opened here - for the amount set here - was paid. Airtel's
     * record has to name that id back before anything is settled.
     *
     * @return string success | failed | pending | token_error | unverified | reference_mismatch | amount_mismatch
     */
    private function settle(TransactionModel $transaction): string
    {
        $transactionId = (string) $transaction->trx_id;
        $airtelId      = (string) ($transaction->trx_data['airtel_transaction_id'] ?? cache()->get('airtel_transaction_'.$transactionId) ?: $transactionId);
        $accessToken   = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('Failed to get Airtel access token for verification', ['trx_id' => $transactionId]);

            return 'token_error';
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'X-Country'     => strtoupper((string) $this->credentials['country']),
                'X-Currency'    => strtoupper((string) ($transaction->payable_currency ?: $this->credentials['currency'])),
            ])->get($this->baseUrl.'/standard/v1/payments/'.rawurlencode($airtelId));
        } catch (Throwable $e) {
            Log::error('Airtel verification request failed: '.$e->getMessage(), ['trx_id' => $transactionId]);

            return 'unverified';
        }

        $result = $response->json();
        $record = is_array($result) ? data_get($result, 'data.transaction') : null;

        if (! $response->successful() || ! is_array($result) || ! $this->envelopeSucceeded($result) || ! is_array($record)) {
            Log::warning('Airtel verification unavailable', ['trx_id' => $transactionId, 'http' => $response->status()]);

            return 'unverified';
        }

        if (! hash_equals($airtelId, (string) ($record['id'] ?? ''))) {
            return 'reference_mismatch';
        }

        $status = strtoupper((string) ($record['status'] ?? ''));

        // A declined prompt, a wrong PIN and a prompt left to expire all end
        // as TF, so the payer is told and the row does not stay pending.
        if ($status === 'TF') {
            Transaction::failTransaction($transactionId);

            return 'failed';
        }

        if ($status !== 'TS') {
            return 'pending';
        }

        // The enquiry does not normally repeat the amount: the collection id
        // is what binds it, and the amount was set here when it was opened.
        // An answer that does carry one still has to agree with what is owed.
        if (array_key_exists('amount', $record)
            && ! CallbackSettlement::amountMatches($transaction, $record['amount'], $record['currency'] ?? null)) {
            return 'amount_mismatch';
        }

        Transaction::completeTransaction($transactionId);
        Log::info('Airtel payment completed for transaction: '.$transactionId);

        return 'success';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function envelopeSucceeded(array $result): bool
    {
        $status = $result['status'] ?? null;

        return is_array($status) && ($status['success'] ?? false) === true;
    }

    private function rememberReference(string $trxId, string $airtelId): void
    {
        $transaction = TransactionModel::query()->where('trx_id', $trxId)->first();

        $transaction?->update([
            'trx_data' => array_merge($transaction->trx_data ?? [], ['airtel_transaction_id' => $airtelId]),
        ]);
    }

    /**
     * Airtel's callback carries no signature, so nothing in it is believed:
     * it only says which collection to ask Airtel about, and settle() acts on
     * Airtel's own answer for that collection.
     *
     * This used to log the whole request body, the payer's number and
     * Airtel's money id included, on every call. It also compared the
     * enquiry's `status` object with the string 'TXN_200', so a callback
     * never completed anything - and it answered every unknown or forged
     * callback with a cheerful 200.
     */
    public function handleIPN(Request $request): JsonResponse
    {
        // Airtel nests it as transaction.id; older integrations posted a
        // flat transaction_id.
        $transactionId = trim((string) ($request->input('transaction.id') ?? $request->input('transaction_id') ?? ''));

        Log::info('Airtel Money IPN received', ['trx_id' => $transactionId !== '' ? $transactionId : null]);

        if ($transactionId === '') {
            return response()->json(['error' => 'Missing transaction ID'], 400);
        }

        $transaction = CallbackSettlement::find($transactionId);

        if ($transaction === null) {
            return $this->refuse('unknown_transaction', $transactionId);
        }

        // A retry for a payment already settled either way changes nothing,
        // and is answered without asking Airtel again.
        if ($transaction->status !== TrxStatus::PENDING) {
            return response()->json(['status' => $transaction->status->value]);
        }

        $outcome = $this->settle($transaction);

        return match ($outcome) {
            'success', 'failed', 'pending' => response()->json(['status' => $outcome]),
            'token_error' => response()->json(['error' => 'Token error'], 503),
            'unverified'  => response()->json(['status' => 'unverified'], 502),
            default       => $this->refuse($outcome, $transactionId),
        };
    }

    private function refuse(string $reason, string $transactionId): JsonResponse
    {
        Log::warning('Airtel IPN refused', ['reason' => $reason, 'trx_id' => $transactionId]);

        return response()->json(['status' => 'rejected'], 422);
    }

    /**
     * The handset the collection prompt is sent to: the payer's own number,
     * without its country code, which Airtel takes from `subscriber.country`.
     *
     * This used to be the administrator's `test_msisdn` for every payment,
     * live included, so no real customer was ever asked to pay. The test
     * number is now only a sandbox convenience for a payment that carries no
     * number of its own. A number from another country is refused rather than
     * sent, because Airtel would read it as a subscriber of its own country.
     *
     * @throws NotifyErrorException
     */
    private function payerMsisdn(string $trxId): string
    {
        $country = (string) ($this->credentials['country'] ?? '');
        $typed   = TransactionModel::query()->where('trx_id', $trxId)->first()?->trx_data['customer_phone'] ?? null;

        if (is_string($typed) && $typed !== '') {
            $msisdn = CustomerPhone::national($typed, $country);

            if ($msisdn === null) {
                throw new NotifyErrorException(__('Enter an Airtel Money number registered in :country.', [
                    'country' => getCountryByCode($country)['name'] ?? strtoupper($country),
                ]));
            }

            return $msisdn;
        }

        $testMsisdn = CustomerPhone::national($this->credentials['test_msisdn'] ?? null, $country);

        if ($this->credentials['sandbox'] && $testMsisdn !== null) {
            return $testMsisdn;
        }

        throw new NotifyErrorException(__('Enter the mobile money number the payment should be requested from.'));
    }

    private function getAccessToken(): ?string
    {
        // Check cache first
        $cacheKey = 'airtel_access_token_'.$this->credentials['client_id'];
        $token    = cache()->get($cacheKey);

        if ($token) {
            return $token;
        }

        // Get new token
        $authData = [
            'client_id'     => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'grant_type'    => 'client_credentials',
        ];

        // A JSON body, as Airtel's token endpoint documents it.
        $response = Http::acceptJson()->post($this->baseUrl.'/auth/oauth2/token', $authData);

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
