<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AmountFlow;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 *
 * `trx_id` is intentionally omitted — Transaction::boot() generates a unique
 * one on creating.
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'          => User::factory(),
            'trx_type'         => TrxType::DEPOSIT,
            'processing_type'  => MethodType::AUTOMATIC,
            'amount'           => 100,
            'amount_flow'      => AmountFlow::PLUS,
            'fee'              => 0,
            'currency'         => 'USD',
            'net_amount'       => 100,
            'payable_amount'   => 100,
            'payable_currency' => 'USD',
            'status'           => TrxStatus::COMPLETED,
        ];
    }

    /**
     * Set every money column from one value. Accepts a string so crypto
     * amounts such as '0.00094118' reach the column unrounded — this is the
     * shape that exposes ledger precision regressions.
     */
    public function amount(string|int|float $amount): static
    {
        return $this->state(fn (): array => [
            'amount'         => $amount,
            'net_amount'     => $amount,
            'payable_amount' => $amount,
        ]);
    }

    public function fee(string|int|float $fee): static
    {
        return $this->state(fn (): array => ['fee' => $fee]);
    }

    public function currency(string $code, ?string $payableCurrency = null): static
    {
        return $this->state(fn (): array => [
            'currency'         => $code,
            'payable_currency' => $payableCurrency ?? $code,
        ]);
    }

    public function type(TrxType $type): static
    {
        return $this->state(fn (): array => ['trx_type' => $type]);
    }

    public function forWallet(Wallet $wallet): static
    {
        return $this->state(fn (): array => [
            'user_id'          => $wallet->user_id,
            'wallet_reference' => $wallet->uuid,
            'currency'         => $wallet->currency->code,
            'payable_currency' => $wallet->currency->code,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => TrxStatus::PENDING]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => TrxStatus::FAILED]);
    }

    public function debit(): static
    {
        return $this->state(fn (): array => ['amount_flow' => AmountFlow::MINUS]);
    }

    public function credit(): static
    {
        return $this->state(fn (): array => ['amount_flow' => AmountFlow::PLUS]);
    }
}
