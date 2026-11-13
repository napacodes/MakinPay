<?php

namespace App\Services\Bitnob;

use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Log;

/**
 * Bitnob deposit / on-ramp service (stablecoin addresses).
 *
 * Verified against the Bitnob sandbox:
 *  1. `POST /api/addresses` (HMAC-signed, api.bitnob.com) with `chain`,
 *     `currency` and an optional `label` returns
 *     `data: {id, chain, address, status, label, reference}`.
 *  2. The customer sends USDT/USDC to that address.
 *  3. Bitnob fires a transfer/deposit webhook keyed by chain + address — the
 *     API does not echo our merchant reference — so the webhook handler
 *     matches the pending Transaction by address, keeping the legacy
 *     reference match as a fallback.
 */
class BitnobDepositService
{
    public function __construct(protected BitnobService $api)
    {
        //
    }

    /**
     * Local network codes mapped to the chain values the address API accepts.
     */
    private const CHAIN_ALIASES = [
        'eth'   => 'ethereum',
        'erc20' => 'ethereum',
        'bep20' => 'bsc',
        'bnb'   => 'bsc',
        'trc20' => 'tron',
        'matic' => 'polygon',
    ];

    /**
     * Generate a deposit address for a user/wallet pair.
     *
     * @return array<string, mixed> Contains `address`, `chain`, `currency`,
     *                              the local `reference`, and the raw payload.
     */
    public function generateAddress(User $user, Wallet $wallet, string $chain = 'ethereum', ?string $reference = null): array
    {
        $reference = $reference              ?? BitnobService::reference('bn_dep');
        $currency  = $wallet->currency->code ?? 'USDT';

        $payload = [
            'chain'    => $this->normalizeChain($chain),
            'currency' => $currency,
            'label'    => $reference,
        ];

        $response = $this->api->post($this->api->url('deposit_address'), $payload);

        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;

        return [
            'address'          => $data['address'] ?? $data['wallet_address'] ?? null,
            'chain'            => $data['chain']   ?? $payload['chain'],
            'currency'         => $currency,
            'reference'        => $reference,
            'provider_address' => $data['reference'] ?? null,
            'raw'              => $data,
        ];
    }

    public function normalizeChain(string $chain): string
    {
        $chain = strtolower(trim($chain));

        return self::CHAIN_ALIASES[$chain] ?? $chain;
    }

    /**
     * Apply a verified `stablecoin.deposit.success` webhook payload to a
     * pending transaction. Caller is responsible for signature verification.
     *
     * The received on-chain amount is reconciled against the transaction's
     * payable amount: underpayments are recorded but left pending for admin
     * review instead of crediting the full requested amount.
     *
     * @param  array<string, mixed>                                 $event
     * @return array{credited: bool, transaction: Transaction|null}
     */
    public function applyDepositSuccess(array $event): array
    {
        $tx = $this->findDepositTransaction($event);

        if (! $tx || $tx->status !== TrxStatus::PENDING) {
            return ['credited' => false, 'transaction' => $tx];
        }

        $trxData                        = $tx->trx_data ?? [];
        $trxData['bitnob_deposit']      = $event;
        $trxData['bitnob_deposit_hash'] = $event['hash'] ?? data_get($event, 'data.hash');
        $trxData['bitnob_deposit_at']   = now()->toIso8601String();

        $receivedAmount = $this->eventAmount($event);

        if ($receivedAmount !== null && $this->isUnderpayment($receivedAmount, (float) $tx->payable_amount)) {
            $trxData['bitnob_underpayment'] = [
                'received' => $receivedAmount,
                'expected' => (float) $tx->payable_amount,
            ];
            $tx->update(['trx_data' => $trxData]);

            Log::warning('Bitnob stablecoin deposit underpaid — left pending for admin review', [
                'trx_id'   => $tx->trx_id,
                'received' => $receivedAmount,
                'expected' => (float) $tx->payable_amount,
            ]);

            return ['credited' => false, 'transaction' => $tx];
        }

        $tx->update(['trx_data' => $trxData]);

        // Credit via the shared completion path so wallet credit, referral
        // rewards, notifications, and events all fire consistently.
        app(TransactionService::class)->completeTransaction(
            $tx->trx_id,
            __('Bitnob stablecoin deposit confirmed.'),
        );

        return ['credited' => true, 'transaction' => $tx->refresh()];
    }

    /**
     * Locate the pending deposit a webhook belongs to. The legacy events echo
     * our reference; the current stablecoins API only sends chain + address,
     * so the generated address stored in trx_data is matched as well.
     *
     * @param array<string, mixed> $event
     */
    public function findDepositTransaction(array $event): ?Transaction
    {
        $reference = self::eventReference($event);

        if ($reference !== '') {
            $tx = Transaction::query()->where('trx_id', $reference)->first()
                ?? Transaction::query()->where('trx_data->bitnob_reference', $reference)->first();

            if ($tx) {
                return $tx;
            }
        }

        $address = self::eventAddress($event);

        if ($address === '') {
            return null;
        }

        return Transaction::query()
            ->where('trx_type', TrxType::DEPOSIT)
            ->where('trx_data->bitnob_deposit_wallet', $address)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Our merchant reference, when the event echoes one. Bitnob's own
     * `RCV_…` reference is not ours, so callers fall back to the address.
     *
     * @param array<string, mixed> $event
     */
    public static function eventReference(array $event): string
    {
        foreach (['reference', 'data.reference', 'data.metadata.reference', 'metadata.reference'] as $path) {
            $value = data_get($event, $path);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * The on-chain address a deposit landed on. Bitnob nests it under
     * `metadata` on transaction-shaped payloads and at the top level or under
     * `data` on transfer-shaped ones, so every known location is checked.
     *
     * @param array<string, mixed> $event
     */
    public static function eventAddress(array $event): string
    {
        foreach ([
            'address',
            'data.address',
            'metadata.address',
            'data.metadata.address',
            'destination_address',
            'data.destination_address',
        ] as $path) {
            $value = data_get($event, $path);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * The credited amount in whole coins.
     *
     * Bitnob reports the raw on-chain value in the asset's smallest unit
     * ("20000000" for 20 USDC) alongside a human figure in
     * `amount_formatted` / `display_amount` ("20.0 USDC"). The formatted
     * value is the only one whose scale is unambiguous, so it wins; the raw
     * amount is used solely as a last resort.
     *
     * @param array<string, mixed> $event
     */
    private function eventAmount(array $event): ?float
    {
        foreach ([
            'amount_formatted',
            'data.amount_formatted',
            'metadata.amount_formatted',
            'display_amount',
            'data.display_amount',
        ] as $path) {
            $value = data_get($event, $path);

            if (is_string($value) && preg_match('/-?\d+(\.\d+)?/', $value, $matches) === 1) {
                return (float) $matches[0];
            }

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        foreach (['amount', 'data.amount', 'metadata.amount'] as $path) {
            $value = data_get($event, $path);

            if (! is_numeric($value)) {
                continue;
            }

            $raw = (float) $value;

            // Deposit webhooks report the raw on-chain value as an integer in
            // the asset's smallest unit ("20000000" = 20 USDC) with no
            // formatted counterpart, so integers are scaled down by the
            // asset's decimals; a value that already carries a fraction is
            // taken as-is. Scaling is applied unconditionally: reading a
            // smallest-unit figure as whole coins would wave a large
            // underpayment through, whereas the reverse merely holds a deposit
            // for review.
            return str_contains((string) $value, '.')
                ? $raw
                : $raw / (10 ** $this->assetDecimals($event));
        }

        return null;
    }

    /**
     * Decimals for the deposited asset on its chain. USDT/USDC use 6 on every
     * chain we enable; BNB Smart Chain is the notable 18-decimal exception.
     *
     * @param array<string, mixed> $event
     */
    private function assetDecimals(array $event): int
    {
        $chain = strtolower((string) (
            data_get($event, 'chain')
            ?? data_get($event, 'data.chain')
            ?? data_get($event, 'metadata.chain')
            ?? ''
        ));

        return in_array($this->normalizeChain($chain), ['bsc'], true) ? 18 : 6;
    }

    private function isUnderpayment(float $received, float $expected): bool
    {
        if ($expected <= 0) {
            return false;
        }

        return ! $this->coversExpected($received, $expected);
    }

    /**
     * Whether a received amount settles the invoice, allowing a 1% tolerance
     * for provider-side fee and rounding differences.
     */
    private function coversExpected(float $received, float $expected): bool
    {
        return $received >= $expected * 0.99;
    }
}
