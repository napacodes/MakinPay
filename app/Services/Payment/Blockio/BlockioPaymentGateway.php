<?php

namespace App\Services\Payment\Blockio;

use App\Exceptions\NotifyErrorException;
use App\Models\PaymentGateway;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\CallbackSettlement;
use App\Support\DecimalMoney;
use App\Support\GatewayCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;
use Transaction;

class BlockioPaymentGateway implements PaymentGatewayInterface
{
    protected $credentials;

    protected $baseUrl = 'https://block.io/api/v2/';

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('blockio');
    }

    /**
     * @throws NotifyErrorException
     */
    public function deposit($amount, $currency, $trxId)
    {
        // Stop before the API call when the key is still the shipped
        // placeholder, so the customer gets an answer rather than the
        // provider's rejection.
        $credentials = GatewayCredentials::require('blockio', ['api_key']);

        try {
            $addressResponse = Http::get($this->baseUrl.'get_new_address/', [
                'api_key' => $credentials['api_key'],
                'label'   => 'payment_'.$trxId,
            ]);
        } catch (ConnectionException $exception) {
            Log::error('Block.io address request could not be sent.', [
                'trx_id' => $trxId,
                'error'  => $exception->getMessage(),
            ]);

            throw new NotifyErrorException(__('Could not reach Block.io just now. Please try again in a moment.'));
        }

        $addressData = $addressResponse->json() ?? [];
        $address     = data_get($addressData, 'data.address');

        if ($addressResponse->successful() && data_get($addressData, 'status') === 'success' && $address) {
            cache()->put('blockio_address_'.$trxId, $address, 3600);

            // `payment.blockio.checkout` has never been defined in this app, so
            // a successful address request used to die on RouteNotFoundException
            // — a 500 after the customer's money had already been committed to
            // the flow. Until that page exists, say so instead of crashing.
            if (! Route::has('payment.blockio.checkout')) {
                Log::error('Block.io created an address but its checkout page does not exist.', [
                    'trx_id'  => $trxId,
                    'address' => $address,
                    'route'   => 'payment.blockio.checkout',
                ]);

                throw new NotifyErrorException(__('This payment method is not available right now. Please choose another one or contact support.'));
            }

            return route('payment.blockio.checkout', [
                'trx_id'   => $trxId,
                'address'  => $address,
                'amount'   => $amount,
                'currency' => $currency,
            ]);
        }

        // The response body is for the log, never for the customer: it is
        // often empty, which is how this surfaced as a message ending in a
        // colon with nothing after it.
        Log::error('Block.io refused to create a payment address.', [
            'trx_id' => $trxId,
            'status' => $addressResponse->status(),
            'error'  => data_get($addressData, 'data.error_message') ?: $addressResponse->body(),
        ]);

        throw new NotifyErrorException(__('Block.io could not create a payment address for this deposit. Please try again or choose another payment method.'));
    }

    /**
     * Block.io's notification is unsigned, so the body is read for two names
     * only — our transaction and the address — and the address has to be the
     * one this deposit was given (cached at deposit, or found at Block.io by
     * the label we set). How much arrived there and how deep it is buried
     * come from Block.io's own transaction list. Before this, `confirmations`
     * was read from the body and the only on-chain check was that the named
     * address — any address — held a non-zero balance.
     */
    public function handleIPN(Request $request): JsonResponse
    {
        $transactionId = trim((string) $request->input('transaction_id', ''));
        $address       = trim((string) $request->input('address', ''));

        if ($transactionId === '' || $address === '') {
            return response()->json(['error' => 'Missing parameters'], 400);
        }

        $transaction = CallbackSettlement::find($transactionId);

        if ($transaction === null) {
            return $this->refuse('unknown_transaction', $transactionId);
        }

        $expectedAddress = $this->addressFor($transactionId);

        if ($expectedAddress === null || ! hash_equals(strtolower($expectedAddress), strtolower($address))) {
            return $this->refuse('address_mismatch', $transactionId);
        }

        try {
            $received = Http::get($this->baseUrl.'get_transactions/', [
                'api_key'   => $this->credentials['api_key'],
                'type'      => 'received',
                'addresses' => $expectedAddress,
            ]);
        } catch (Throwable $e) {
            Log::error('Block.io transaction lookup failed: '.$e->getMessage(), ['trx_id' => $transactionId]);

            return response()->json(['status' => 'unverified'], 502);
        }

        $body = $received->json();

        if (! $received->successful() || ! is_array($body) || ($body['status'] ?? null) !== 'success') {
            Log::warning('Block.io verification unavailable', ['trx_id' => $transactionId, 'http' => $received->status()]);

            return response()->json(['status' => 'unverified'], 502);
        }

        $requiredConfirmations = max(1, (int) ($this->credentials['required_confirmations'] ?? 1));
        $confirmed             = '0';

        foreach ((array) data_get($body, 'data.txs', []) as $tx) {
            if ((int) ($tx['confirmations'] ?? 0) < $requiredConfirmations) {
                continue;
            }

            foreach ((array) ($tx['amounts_received'] ?? []) as $receipt) {
                if (strcasecmp((string) ($receipt['recipient'] ?? ''), $expectedAddress) === 0 && is_numeric($receipt['amount'] ?? null)) {
                    $confirmed = DecimalMoney::add($confirmed, (string) $receipt['amount'], 8);
                }
            }
        }

        if (! CallbackSettlement::amountCovers($transaction, $confirmed)) {
            Log::info('Block.io payment not yet confirmed in full', [
                'trx_id'    => $transactionId,
                'confirmed' => $confirmed,
                'required'  => $requiredConfirmations,
            ]);

            return response()->json(['status' => 'pending']);
        }

        Transaction::completeTransaction($transactionId);
        Log::info('Block.io payment completed for transaction: '.$transactionId);

        return response()->json(['status' => 'success']);
    }

    /**
     * The address this deposit was given. The cache entry from deposit comes
     * first; a confirmation that takes longer than its hour falls back to the
     * label Block.io keeps, which is ours by construction.
     */
    private function addressFor(string $transactionId): ?string
    {
        $cached = cache()->get('blockio_address_'.$transactionId);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::get($this->baseUrl.'get_address_by_label/', [
                'api_key' => $this->credentials['api_key'],
                'label'   => 'payment_'.$transactionId,
            ]);
        } catch (Throwable $e) {
            Log::error('Block.io address lookup failed: '.$e->getMessage(), ['trx_id' => $transactionId]);

            return null;
        }

        $address = data_get($response->json(), 'data.address');

        return $response->successful() && is_string($address) && $address !== '' ? $address : null;
    }

    private function refuse(string $reason, string $transactionId): JsonResponse
    {
        Log::warning('Block.io IPN refused', ['reason' => $reason, 'trx_id' => $transactionId]);

        return response()->json(['status' => 'rejected'], 422);
    }
}
