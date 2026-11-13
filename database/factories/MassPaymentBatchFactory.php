<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Models\MassPaymentBatch;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An empty draft batch paid from a wallet of its owner, in that wallet's
 * currency.
 *
 * It writes rows only: no wallet is debited and no ledger row is created. Use
 * it to shape state for a test, never to stand in for MassPaymentService.
 *
 * @extends Factory<MassPaymentBatch>
 */
class MassPaymentBatchFactory extends Factory
{
    protected $model = MassPaymentBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid'      => (string) Str::uuid(),
            'reference' => MassPaymentBatch::generateReference(),
            'user_id'   => User::factory(),
            'wallet_id' => fn (array $attributes): int => (int) Wallet::factory()
                ->create(['user_id' => $attributes['user_id']])
                ->getKey(),
            'currency_id'           => fn (array $attributes): int => (int) $this->wallet($attributes)->currency_id,
            'currency_code'         => fn (array $attributes): string => (string) $this->wallet($attributes)->currency->code,
            'kind'                  => 'wallet',
            'source'                => 'csv',
            'channel'               => 'web',
            'original_filename'     => 'payouts.csv',
            'status'                => MassPaymentBatchStatus::Draft->value,
            'skip_invalid'          => false,
            'warnings_acknowledged' => false,
            'preview_fingerprint'   => hash('sha256', Str::random(32)),
            'expires_at'            => now()->addDay(),
        ];
    }

    /** Paid from this wallet, by its owner, in its currency. */
    public function forWallet(Wallet $wallet): static
    {
        return $this->state(fn (): array => [
            'user_id'       => $wallet->user_id,
            'wallet_id'     => $wallet->id,
            'currency_id'   => $wallet->currency_id,
            'currency_code' => (string) $wallet->currency->code,
        ]);
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status'           => MassPaymentBatchStatus::Queued->value,
            'confirmed_at'     => now(),
            'last_progress_at' => now(),
            'exchange_rate'    => '1',
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn (): array => [
            'status'                => MassPaymentBatchStatus::Processing->value,
            'processing_started_at' => now(),
        ]);
    }

    public function needsReview(string $reason = 'ledger_anomaly'): static
    {
        return $this->processing()->state(fn (): array => [
            'status'      => MassPaymentBatchStatus::NeedsReview->value,
            'hold_reason' => $reason,
        ]);
    }

    public function completed(): static
    {
        return $this->processing()->state(fn (): array => [
            'status'       => MassPaymentBatchStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function wallet(array $attributes): Wallet
    {
        return Wallet::query()->with('currency')->findOrFail($attributes['wallet_id']);
    }
}
