<?php

declare(strict_types=1);

namespace App\Services\Payment\Bitnob;

use App\Models\DepositMethod;
use App\Models\Transaction as TransactionModel;
use App\Models\Wallet;
use App\Services\Bitnob\BitnobDepositService;
use App\Services\Bitnob\BitnobPayoutService;
use App\Services\Bitnob\BitnobProviderSettings;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Services\TransactionService;
use App\Support\CryptoAddressValidator;
use App\Support\WithdrawFieldNormalizer;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bitnob deposit / IPN handler.
 *
 * Used by the IPN controller (`POST /ipn/bitnob`) to receive Bitnob's
 * deposit, card, and payout webhook callbacks. Provider event processing is
 * delegated to BitnobWebhookHandler after this driver verifies the signature.
 *
 * Mirrors the working voaray reference verbatim: webhook signature is
 * tried as both HMAC-SHA256 and HMAC-SHA512, in hex and base64, against
 * any of the documented Bitnob secret keys.
 */
class BitnobPaymentGateway implements PaymentGatewayInterface
{
    private const SUCCESS_STATUSES = [
        'success',
        'successful',
        'completed',
        'complete',
        'approved',
        'paid',
    ];

    private const FAILED_STATUSES = [
        'failed',
        'fail',
        'declined',
        'canceled',
        'cancelled',
        'reversed',
    ];

    protected array $credentials;

    public function __construct(
        protected BitnobProviderSettings $settings,
        protected BitnobWebhookHandler $webhooks,
    ) {
        $this->credentials = $this->settings->credentials();
    }

    /**
     * Map deposit method codes to the chain values Bitnob's address API
     * accepts. Which chains an account may actually use is enabled per
     * account by Bitnob — unsupported ones are rejected with a clear
     * "chain is not supported for address generation" error.
     */
    private const CHAIN_BY_METHOD_CODE = [
        'bitnob_usdt'         => 'ethereum',
        'bitnob-usdt-trc20'   => 'tron',
        'bitnob-usdt-bep20'   => 'bsc',
        'bitnob-usdt-erc20'   => 'ethereum',
        'bitnob-usdt-polygon' => 'polygon',
        'bitnob-usdt-base'    => 'base',
        'bitnob-usdt-solana'  => 'solana',
        'bitnob-usdc-trc20'   => 'tron',
        'bitnob-usdc-bep20'   => 'bsc',
        'bitnob-usdc-erc20'   => 'ethereum',
        'bitnob-usdc-polygon' => 'polygon',
        'bitnob-usdc-base'    => 'base',
        'bitnob-usdc-solana'  => 'solana',
    ];

    private const CHAIN_LABELS = [
        'tron'     => 'TRON (TRC20)',
        'bsc'      => 'BNB Smart Chain (BEP20)',
        'eth'      => 'Ethereum (ERC20)',
        'ethereum' => 'Ethereum (ERC20)',
        'polygon'  => 'Polygon',
        'base'     => 'Base',
        'solana'   => 'Solana',
    ];

    /**
     * Generate a personal stablecoin deposit address for the pending
     * transaction and send the user to the address page. The wallet is
     * credited later by the `stablecoin.deposit.success` webhook.
     *
     * @throws Exception
     */
    public function deposit($amount, $currency, $trxId)
    {
        $transaction = TransactionModel::query()->where('trx_id', $trxId)->firstOrFail();

        $wallet = Wallet::query()->where('uuid', $transaction->wallet_reference)->first();
        if (! $wallet) {
            throw new Exception(__('The deposit wallet could not be found.'));
        }

        $chain   = $this->depositChain();
        $address = app(BitnobDepositService::class)->generateAddress(
            $transaction->user,
            $wallet,
            $chain,
            $trxId
        );

        if (empty($address['address'])) {
            throw new Exception(__('Bitnob did not return a deposit address. Please try again.'));
        }

        $transaction->update([
            'trx_data' => array_merge($transaction->trx_data ?? [], [
                'bitnob_reference'      => $trxId,
                'bitnob_deposit_chain'  => $address['chain']                               ?? $chain,
                'bitnob_deposit_label'  => self::CHAIN_LABELS[$address['chain'] ?? $chain] ?? strtoupper((string) ($address['chain'] ?? $chain)),
                'bitnob_deposit_wallet' => $address['address'],
            ]),
        ]);

        return route('user.deposit.crypto.show', ['trx_id' => $trxId]);
    }

    /**
     * Resolve the target chain from the deposit method the user selected.
     */
    protected function depositChain(): string
    {
        $methodId = request()->input('payment_method');
        $method   = $methodId ? DepositMethod::query()->find($methodId) : null;

        return self::CHAIN_BY_METHOD_CODE[$method?->method_code] ?? 'ethereum';
    }

    /**
     * @param  array<string, mixed>|string|null $withdrawCredential
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function withdraw($amount, $currency, $trxId, $withdrawCredential): array
    {
        $credentials = is_array($withdrawCredential)
            ? $withdrawCredential
            : WithdrawFieldNormalizer::values($withdrawCredential);

        if ($this->credentialValue($credentials, ['wallet_address', 'address']) !== null) {
            return $this->withdrawStablecoin($amount, $currency, $trxId, $credentials);
        }

        $destinationType = $this->credentialValue($credentials, ['destination_type']) ?? 'bank';
        $country         = strtoupper((string) ($this->credentialValue($credentials, ['country']) ?? ''));
        $bankCode        = $this->credentialValue($credentials, ['bank_code', 'bank']);
        $accountNumber   = $this->credentialValue($credentials, ['account_number', 'phone_number', 'mobile_number']);
        $accountName     = $this->credentialValue($credentials, ['account_name', 'name', 'recipient_name']);

        $this->assertWithdrawCredential($country, 'country');
        $this->assertWithdrawCredential($bankCode, 'bank_code');
        $this->assertWithdrawCredential($accountNumber, 'account_number');
        $this->assertWithdrawCredential($accountName, 'account_name');

        $beneficiary = array_filter([
            'destination_type' => $destinationType,
            'country'          => $country,
            'account_name'     => $accountName,
            'account_number'   => $accountNumber,
            'bank_code'        => $bankCode,
            'swift_code'       => $this->credentialValue($credentials, ['swift_code']),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $response = app(BitnobPayoutService::class)->payout(
            [
                'settlement_amount' => (float) $amount,
                'country'           => $country,
                'to_currency'       => strtoupper((string) $currency),
                'reference'         => $trxId,
            ],
            $beneficiary,
            route('ipn.handle', ['gateway' => 'bitnob']),
            'vendor_payment',
            $trxId
        );

        $status = data_get($response, 'data.status') ?? data_get($response, 'status') ?? 'pending';

        return [
            'reference'   => $trxId,
            'status'      => strtolower((string) $status),
            'beneficiary' => $beneficiary,
            'data'        => data_get($response, 'data', []),
            'raw'         => $response,
        ];
    }

    /**
     * Send USDT directly to the user's on-chain address. The transaction
     * stays pending until the `payouts.withdrawal.success` webhook lands.
     *
     * @param  array<string, mixed> $credentials
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    protected function withdrawStablecoin($amount, $currency, string $trxId, array $credentials): array
    {
        $address = (string) $this->credentialValue($credentials, ['wallet_address', 'address']);
        $network = CryptoAddressValidator::detectNetwork(
            $this->credentialValue($credentials, ['network', 'chain'])
        );

        if ($network === null) {
            throw new Exception(__('The withdrawal network is missing or unsupported.'));
        }

        if (! CryptoAddressValidator::isValid($network, $address)) {
            throw new Exception(__('The wallet address is not a valid :network address.', [
                'network' => CryptoAddressValidator::labelFor($network),
            ]));
        }

        $response = app(BitnobPayoutService::class)->sendStablecoin([
            'address'     => $address,
            'chain'       => CryptoAddressValidator::chainFor($network),
            'amount'      => (float) $amount,
            'currency'    => strtoupper((string) $currency),
            'reference'   => $trxId,
            'description' => 'wallet_withdrawal',
        ]);

        $status = data_get($response, 'data.status') ?? data_get($response, 'status') ?? 'pending';

        return [
            'reference'   => $trxId,
            'status'      => strtolower((string) $status),
            'beneficiary' => [
                'destination_type' => 'crypto',
                'wallet_address'   => $address,
                'network'          => $network,
            ],
            'data' => data_get($response, 'data', []),
            'raw'  => $response,
        ];
    }

    public function handleIPN(Request $request): JsonResponse
    {
        try {
            if (! $this->verifyWebhookSignature($request)) {
                Log::warning('Bitnob webhook signature verification failed');

                return response()->json(['error' => 'Invalid signature'], 401);
            }

            $payload = $request->json()->all();
            if (! is_array($payload) || $payload === []) {
                $raw     = (string) $request->getContent();
                $decoded = $raw !== '' ? json_decode($raw, true) : null;
                $payload = is_array($decoded) ? $decoded : $request->all();
            }

            $event = (string) ($payload['event'] ?? '');
            if ($this->settings->webhookHandler($event) !== null) {
                Log::info('Bitnob IPN dispatching provider event', [
                    'event' => $event,
                ]);

                return $this->webhooks->handle($request);
            }

            $reference = $this->extractReference($payload);
            if (! $reference) {
                // Stablecoin deposits arrive without any reference of ours, so
                // the dedicated handler gets a chance to match by the address
                // we generated before the payload is dropped.
                Log::info('Bitnob webhook without a reference; delegating for address match', [
                    'event'        => $event,
                    'payload_keys' => is_array($payload) ? array_keys($payload) : null,
                ]);

                return $this->webhooks->handle($request);
            }

            $status = strtolower((string) ($this->extractStatus($payload) ?? ''));
            if ($status === '') {
                Log::info('Bitnob webhook missing status (acknowledged)', ['reference' => $reference]);

                return response()->json(['status' => 'success']);
            }

            $trx = $this->findPendingTransactionByReference($reference);

            if (! $trx) {
                // Stablecoin deposits carry Bitnob's own `RCV_…` reference, not
                // ours, so a miss here is expected rather than terminal: hand
                // the payload to the dedicated handler, which matches a pending
                // deposit by the address we generated.
                Log::info('Bitnob webhook: no transaction for reference; delegating for address match', [
                    'reference' => $reference,
                    'event'     => $event,
                ]);

                return $this->webhooks->handle($request);
            }

            // Always attach webhook payload for audit/debug.
            $trx->update([
                'trx_data' => array_merge($trx->trx_data ?? [], [
                    'bitnob_webhook' => $payload,
                ]),
            ]);

            if (in_array($status, self::SUCCESS_STATUSES, true)) {
                app(TransactionService::class)->completeTransaction(
                    $trx->trx_id,
                    'Bitnob: '.$status,
                    $trx->description
                );

                return response()->json(['status' => 'success']);
            }

            if (in_array($status, self::FAILED_STATUSES, true)) {
                app(TransactionService::class)->failTransaction(
                    $trx->trx_id,
                    'Bitnob: '.$status,
                    $trx->description
                );

                return response()->json(['status' => 'success']);
            }

            Log::info('Bitnob webhook: unhandled status (acknowledged)', [
                'reference' => $reference,
                'status'    => $status,
            ]);

            return response()->json(['status' => 'success']);
        } catch (\Throwable $e) {
            Log::error('Bitnob webhook error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    protected function findPendingTransactionByReference(string $reference): ?TransactionModel
    {
        return TransactionModel::query()
            ->where('status', 'pending')
            ->where(function ($q) use ($reference) {
                $q->where('trx_reference', $reference)
                    ->orWhere('trx_id', $reference)
                    ->orWhere('trx_data->reference', $reference)
                    ->orWhere('trx_data->data->reference', $reference)
                    ->orWhere('trx_data->bitnob_withdraw->reference', $reference)
                    ->orWhere('trx_data->bitnob_withdraw->data->reference', $reference)
                    ->orWhere('trx_data->bitnob_withdraw->raw->data->reference', $reference)
                    ->orWhere('trx_data->transaction->reference', $reference)
                    ->orWhere('trx_data->data->paymentReference', $reference)
                    ->orWhere('trx_data->data->transactionReference', $reference);
            })
            ->latest('id')
            ->first();
    }

    protected function verifyWebhookSignature(Request $request): bool
    {
        $secret = (string) ($this->credentials['webhook_secret']
            ?? $this->credentials['webhook_signing_secret']
            ?? $this->credentials['signing_secret']
            ?? $this->credentials['webhook_key']
            ?? $this->credentials['webhook_token']
            ?? $this->credentials['secret']
            ?? '');

        if ($this->isBlankSecret($secret)) {
            return false;
        }

        $signature = (string) ($request->header('X-Bitnob-Signature')
            ?? $request->header('x-bitnob-signature')
            ?? $request->header('X-Bitnob-Signature-256')
            ?? $request->header('x-bitnob-signature-256')
            ?? $request->header('X-Bitnob-Signature-512')
            ?? $request->header('x-bitnob-signature-512')
            ?? $request->header('X-Signature')
            ?? $request->header('x-signature')
            ?? '');

        if ($signature === '') {
            return false;
        }

        $payload = (string) $request->getContent();
        $sig     = $this->normalizeSignature(trim($signature));

        // Bitnob may send the HMAC as hex or base64, sha256 or sha512.
        $hashes = [
            hash_hmac('sha256', $payload, $secret),
            hash_hmac('sha512', $payload, $secret),
        ];

        foreach ($hashes as $hex) {
            if (hash_equals($hex, $sig)) {
                return true;
            }

            $binary = hex2bin($hex);
            if ($binary !== false && hash_equals(base64_encode($binary), $sig)) {
                return true;
            }
        }

        Log::warning('Bitnob webhook signature mismatch', [
            'signature_length' => strlen($sig),
            'payload_length'   => strlen($payload),
        ]);

        return false;
    }

    protected function isBlankSecret(string $secret): bool
    {
        $normalized = strtolower(trim($secret));

        return $normalized === ''
            || in_array($normalized, [
                'webhook_secret',
                'webhook_signing_secret',
                'signing_secret',
                'webhook_key',
                'webhook_token',
                'secret',
            ], true);
    }

    protected function normalizeSignature(string $signature): string
    {
        foreach (['sha256=', 'sha512=', 'hmac-sha256=', 'hmac-sha512='] as $prefix) {
            if (str_starts_with(strtolower($signature), $prefix)) {
                return substr($signature, strlen($prefix));
            }
        }

        return $signature;
    }

    protected function extractReference(array $payload): ?string
    {
        $candidates = [
            $payload['data']['reference']                ?? null,
            $payload['data']['paymentReference']         ?? null,
            $payload['data']['transactionReference']     ?? null,
            $payload['data']['customerReference']        ?? null,
            $payload['data']['data']['reference']        ?? null,
            $payload['data']['transaction']['reference'] ?? null,
            $payload['data']['transaction']['id']        ?? null,
            $payload['reference']                        ?? null,
            $payload['paymentReference']                 ?? null,
            $payload['transactionReference']             ?? null,
            $payload['customerReference']                ?? null,
            $payload['id']                               ?? null,
        ];

        foreach ($candidates as $candidate) {
            $reference = $this->stringValue($candidate);
            if ($reference !== null) {
                return $reference;
            }
        }

        return null;
    }

    protected function extractStatus(array $payload): ?string
    {
        $candidates = [
            $payload['data']['status']                ?? null,
            $payload['data']['state']                 ?? null,
            $payload['data']['data']['status']        ?? null,
            $payload['data']['transaction']['status'] ?? null,
            $payload['data']['transaction']['state']  ?? null,
            $payload['status']                        ?? null,
            $payload['state']                         ?? null,
            $payload['event']                         ?? null,
            $payload['type']                          ?? null,
        ];

        foreach ($candidates as $candidate) {
            $status = $this->stringValue($candidate);
            if ($status !== null) {
                return $status;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $credentials
     * @param array<int, string>   $keys
     */
    protected function credentialValue(array $credentials, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($credentials[$key]) && is_scalar($credentials[$key])) {
                $value = trim((string) $credentials[$key]);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    protected function assertWithdrawCredential(?string $value, string $field): void
    {
        if ($value === null || $value === '') {
            throw new Exception('Bitnob withdrawal credential is missing: '.$field);
        }
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
