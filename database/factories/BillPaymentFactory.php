<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillPayment\BillPaymentDispatchState;
use App\Enums\BillPayment\BillPaymentFulfilment;
use App\Enums\BillPayment\BillPaymentStatus;
use App\Models\Biller;
use App\Models\BillPayment;
use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A reserved, operator-fulfilled payment of 25 in the biller's currency, with
 * every snapshot copied from the biller the way the service copies it.
 *
 * It writes rows only: no wallet is debited and no ledger row is created. Use
 * it to shape state for a test, never to stand in for BillPaymentService.
 *
 * @extends Factory<BillPayment>
 */
class BillPaymentFactory extends Factory
{
    protected $model = BillPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid'                     => $uuid,
            'reference'                => BillPayment::generateReference(),
            'user_id'                  => User::factory(),
            'biller_id'                => Biller::factory(),
            'wallet_id'                => fn (array $attributes): int => $this->walletFor($attributes),
            'biller_item_id'           => null,
            'bill_payment_provider_id' => fn (array $attributes): int => (int) $this->biller($attributes)->bill_payment_provider_id,
            'transaction_id'           => null,
            'refund_transaction_id'    => null,
            'biller_name'              => fn (array $attributes): string => (string) $this->biller($attributes)->name,
            'category_code'            => fn (array $attributes): string => (string) $this->biller($attributes)->category->code,
            'item_name'                => null,
            'item_code'                => null,
            'country'                  => fn (array $attributes): string => (string) $this->biller($attributes)->country,
            'customer_number_label'    => fn (array $attributes): string => (string) $this->biller($attributes)->customer_number_label,
            'customer_number'          => fake()->numerify('##########'),
            'provider_code'            => fn (array $attributes): string => (string) $this->biller($attributes)->provider->code,
            'driver'                   => fn (array $attributes): string => (string) $this->biller($attributes)->provider->driver,
            'fulfilment'               => BillPaymentFulfilment::Operator->value,
            'bill_amount'              => '25.00000000',
            'bill_currency'            => fn (array $attributes): string => (string) $this->biller($attributes)->currency,
            'amount'                   => '25.00000000',
            'fee'                      => '0',
            'total_amount'             => '25.00000000',
            'currency'                 => fn (array $attributes): string => (string) $this->biller($attributes)->currency,
            'exchange_rate'            => null,
            'status'                   => BillPaymentStatus::Pending->value,
            'dispatch_state'           => BillPaymentDispatchState::Reserved->value,
            'provider_request_id'      => 'bp_'.$uuid,
            'provider_transaction_id'  => null,
            'receipt_reference'        => null,
            'token'                    => null,
            'client_idempotency_key'   => null,
            'client_request_hash'      => null,
            'channel'                  => 'web',
            'failure_reason'           => null,
            'admin_note'               => null,
            'metadata'                 => null,
            'submitted_at'             => null,
            'processed_at'             => null,
        ];
    }

    /** Handed to the Manual driver and waiting for an operator. */
    public function awaitingOperator(): static
    {
        return $this->state(fn (): array => [
            'status'         => BillPaymentStatus::Processing->value,
            'dispatch_state' => BillPaymentDispatchState::AwaitingOperator->value,
            'submitted_at'   => now(),
        ]);
    }

    public function needsReview(): static
    {
        return $this->state(fn (): array => [
            'status'         => BillPaymentStatus::Processing->value,
            'dispatch_state' => BillPaymentDispatchState::NeedsReview->value,
            'submitted_at'   => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status'            => BillPaymentStatus::Completed->value,
            'dispatch_state'    => BillPaymentDispatchState::Settled->value,
            'receipt_reference' => 'RCPT-'.fake()->numerify('######'),
            'submitted_at'      => now(),
            'processed_at'      => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status'         => BillPaymentStatus::Failed->value,
            'dispatch_state' => BillPaymentDispatchState::Refunded->value,
            'failure_reason' => 'The biller did not accept the payment.',
            'submitted_at'   => now(),
            'processed_at'   => now(),
        ]);
    }

    /** Fulfilled by an API provider rather than an operator. */
    public function viaProvider(): static
    {
        return $this->state(fn (): array => ['fulfilment' => BillPaymentFulfilment::Provider->value]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function biller(array $attributes): Biller
    {
        return Biller::query()->with(['category', 'provider'])->findOrFail($attributes['biller_id']);
    }

    /**
     * A wallet for the payment's user in the biller's currency, which is the
     * only pairing GF2 accepts.
     *
     * @param array<string, mixed> $attributes
     */
    private function walletFor(array $attributes): int
    {
        $code     = (string) $this->biller($attributes)->currency;
        $currency = Currency::query()->where('code', $code)->first()
            ?? Currency::factory()->code($code)->create();

        return (int) Wallet::factory()
            ->forCurrency($currency)
            ->create(['user_id' => $attributes['user_id']])
            ->getKey();
    }
}
