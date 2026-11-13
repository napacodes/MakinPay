<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MassPayment\MassPaymentIssue;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A valid line paying 10.00 plus a 0.50 fee to another user's wallet in the
 * batch's currency, typed as that wallet's ID.
 *
 * Line numbers follow the file: the header is line 1, so a batch's first row
 * is line 2 and each further row takes the next number. The next number is
 * read from the rows already stored, so create several rows of one batch one
 * at a time, or pass `line_no` through a sequence: `count()` makes every row
 * before it stores any.
 *
 * It writes rows only: no wallet moves and no ledger row is created.
 *
 * @extends Factory<MassPaymentRow>
 */
class MassPaymentRowFactory extends Factory
{
    protected $model = MassPaymentRow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mass_payment_batch_id' => MassPaymentBatch::factory(),
            'line_no'               => fn (array $attributes): int => 1 + max(1, (int) MassPaymentRow::query()
                ->where('mass_payment_batch_id', $attributes['mass_payment_batch_id'])
                ->max('line_no')),
            'recipient_user_id'   => User::factory(),
            'recipient_wallet_id' => fn (array $attributes): int => (int) Wallet::factory()
                ->create([
                    'user_id'     => $attributes['recipient_user_id'],
                    'currency_id' => MassPaymentBatch::query()->findOrFail($attributes['mass_payment_batch_id'])->currency_id,
                ])
                ->getKey(),
            'recipient_input' => fn (array $attributes): string => (string) Wallet::query()
                ->whereKey($attributes['recipient_wallet_id'])
                ->value('uuid'),
            'recipient_type'   => 'wallet_id',
            'currency_input'   => null,
            'client_reference' => null,
            'recipient_label'  => fake()->firstName().' '.strtoupper(fake()->randomLetter()).'.',
            'amount_input'     => '10',
            'entered_amount'   => '10',
            'base_amount'      => '10',
            'base_fee'         => '0.5',
            'net_amount'       => '10',
            'payable_amount'   => '10.5',
            'wallet_fee'       => '0.5',
            'exchange_rate'    => '1',
            'note'             => null,
            'status'           => MassPaymentRowStatus::Valid->value,
            'needs_review'     => false,
            'review_reason'    => null,
            'issue_code'       => null,
            'warnings'         => null,
            'attempts'         => 0,
        ];
    }

    /** A line that failed validation: no pinned wallet and no figures. */
    public function invalid(MassPaymentIssue $issue = MassPaymentIssue::UnknownRecipient): static
    {
        return $this->state(fn (): array => [
            'status'              => MassPaymentRowStatus::Invalid->value,
            'issue_code'          => $issue->value,
            'recipient_user_id'   => null,
            'recipient_wallet_id' => null,
            'recipient_input'     => 'nobody@example.com',
            'recipient_type'      => 'email',
            'recipient_label'     => null,
            'entered_amount'      => null,
            'base_amount'         => null,
            'base_fee'            => null,
            'net_amount'          => null,
            'payable_amount'      => null,
            'wallet_fee'          => null,
            'exchange_rate'       => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => MassPaymentRowStatus::Pending->value]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status'  => MassPaymentRowStatus::Paid->value,
            'paid_at' => now(),
        ]);
    }

    public function failed(MassPaymentIssue $issue = MassPaymentIssue::RecipientUnavailable): static
    {
        return $this->state(fn (): array => [
            'status'     => MassPaymentRowStatus::Failed->value,
            'issue_code' => $issue->value,
            'failed_at'  => now(),
        ]);
    }

    public function flagged(string $reason = 'ledger_anomaly'): static
    {
        return $this->pending()->state(fn (): array => [
            'needs_review'  => true,
            'review_reason' => $reason,
        ]);
    }
}
