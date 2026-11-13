<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MassPayment\MassPaymentDispatchState;
use App\Enums\MassPayment\MassPaymentIssue;
use App\Enums\MassPayment\MassPaymentIssueHint;
use App\Enums\MassPayment\MassPaymentRowStatus;
use Database\Factories\MassPaymentRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a mass payment: the recipient as typed, the account and wallet
 * it was pinned to, the frozen figures and the ledger rows its money moved
 * through.
 *
 * Money is cast `decimal:8` (the rate `decimal:18`) and reads back as exact
 * strings. `status` and `issue_code` stay plain strings, with
 * {@see self::status()} and {@see self::issue()} reading them as enums.
 * `issue_code` is a stable code, never translated text.
 *
 * A payout line also carries `dispatch_state` (read by
 * {@see self::dispatchState()}, null on a wallet line) and the account it is
 * sent to in `payout_account`, encrypted with APP_KEY by the cast and hidden
 * from serialisation. Rows written with `insert()` skip casts, so that path
 * must take the ciphertext from `forceFill()->getAttributes()`, never build
 * it by hand.
 */
class MassPaymentRow extends Model
{
    /** @use HasFactory<MassPaymentRowFactory> */
    use HasFactory;

    /**
     * A line nobody has validated is invalid, the one status that is never
     * debited. Defaulting it here also keeps `status` present on an unsaved
     * model, so reading `$row->status` never falls through to the
     * {@see self::status()} method as if it were a relation.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'invalid',
    ];

    protected $fillable = [
        'mass_payment_batch_id',
        'line_no',
        'recipient_input',
        'recipient_type',
        'currency_input',
        'client_reference',
        'recipient_user_id',
        'recipient_wallet_id',
        'recipient_label',
        'amount_input',
        'entered_amount',
        'base_amount',
        'base_fee',
        'net_amount',
        'payable_amount',
        'wallet_fee',
        'exchange_rate',
        'note',
        'status',
        'needs_review',
        'review_reason',
        'issue_code',
        'warnings',
        'metadata',
        'debit_transaction_id',
        'credit_transaction_id',
        'refund_transaction_id',
        'attempts',
        'paid_at',
        'failed_at',
        'dispatch_state',
        'payout_driver',
        'provider_request_id',
        'provider_reference',
        'provider_status',
        'payout_account',
        'payout_account_hash',
        'submitted_at',
        'last_checked_at',
        'next_check_at',
        'settled_at',
        'last_check_outcome',
        'check_attempts',
        'failure_reason',
        'resolved_by_admin_id',
    ];

    /**
     * The decrypted account a payout line is sent to never leaves the model
     * by serialisation: not in an API resource, a JSON response or a log
     * context built from `toArray()`.
     *
     * @var list<string>
     */
    protected $hidden = [
        'payout_account',
    ];

    protected function casts(): array
    {
        return [
            'line_no'        => 'integer',
            'entered_amount' => 'decimal:8',
            'base_amount'    => 'decimal:8',
            'base_fee'       => 'decimal:8',
            'net_amount'     => 'decimal:8',
            'payable_amount' => 'decimal:8',
            'wallet_fee'     => 'decimal:8',
            'exchange_rate'  => 'decimal:18',
            'needs_review'   => 'boolean',
            'warnings'       => 'array',
            'metadata'       => 'array',
            'attempts'       => 'integer',
            'paid_at'        => 'datetime',
            'failed_at'      => 'datetime',

            'payout_account'       => 'encrypted:array',
            'check_attempts'       => 'integer',
            'resolved_by_admin_id' => 'integer',
            'submitted_at'         => 'datetime',
            'last_checked_at'      => 'datetime',
            'next_check_at'        => 'datetime',
            'settled_at'           => 'datetime',
        ];
    }

    /**
     * Where a payout line stands with its provider, or null for a wallet
     * line, which never has one. An unrecognised stored value reads as
     * NeedsReview, so nothing refunds or re-sends a line it does not
     * understand.
     */
    public function dispatchState(): ?MassPaymentDispatchState
    {
        $stored = $this->getAttribute('dispatch_state');

        if ($stored === null) {
            return null;
        }

        return MassPaymentDispatchState::tryFrom((string) $stored) ?? MassPaymentDispatchState::NeedsReview;
    }

    /** The stored status as the enum, or null for a value this code does not know. */
    public function status(): ?MassPaymentRowStatus
    {
        return MassPaymentRowStatus::tryFrom((string) $this->getAttribute('status'));
    }

    /** The stored issue code as the enum, or null when the line has none. */
    public function issue(): ?MassPaymentIssue
    {
        return MassPaymentIssue::tryFrom((string) $this->getAttribute('issue_code'));
    }

    /** The more precise instruction the draft stored for a refused line, if any. */
    public function issueHint(): ?MassPaymentIssueHint
    {
        if ($this->issue() === null) {
            return null;
        }

        $metadata = $this->getAttribute('metadata');
        $hint     = is_array($metadata) ? ($metadata['issue_hint'] ?? null) : null;

        return is_string($hint) ? MassPaymentIssueHint::tryFrom($hint) : null;
    }

    /**
     * The sentence to show next to the line's issue: its hint when the draft
     * stored one, otherwise the issue's own message. Null for a line with no
     * issue.
     */
    public function issueMessage(): ?string
    {
        return $this->issueHint()?->message() ?? $this->issue()?->message();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MassPaymentBatch::class, 'mass_payment_batch_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function recipientWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'recipient_wallet_id');
    }

    /** The PENDING MASS_PAYMENT debit confirm reserved for this line. */
    public function debitTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'debit_transaction_id');
    }

    /** The RECEIVE_MONEY credit written when the line was paid. */
    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'credit_transaction_id');
    }

    /** The REFUND written when the line failed or was stopped. */
    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'refund_transaction_id');
    }
}
