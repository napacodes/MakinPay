<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a card's own ledger: a purchase or refund the provider
 * reported, a top-up or withdrawal the platform made, or a payment the
 * card declined and why.
 */
class VirtualCardActivity extends Model
{
    protected $table = 'virtual_card_activity';

    public const KIND_PURCHASE = 'purchase';

    public const KIND_REFUND = 'refund';

    public const KIND_TOPUP = 'topup';

    public const KIND_WITHDRAW = 'withdraw';

    public const KIND_DECLINE = 'decline';

    public const KIND_FEE = 'fee';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_REVERSED = 'reversed';

    /** Why a payment was refused: the one thing the app can offer to fix. */
    public const REASON_LIMIT = 'limit';

    public const REASON_FROZEN = 'frozen';

    public const REASON_CONTROL = 'control';

    public const REASON_INSUFFICIENT = 'insufficient';

    public const REASON_ISSUER = 'issuer';

    public const REASONS = [self::REASON_LIMIT, self::REASON_FROZEN, self::REASON_CONTROL, self::REASON_INSUFFICIENT, self::REASON_ISSUER];

    protected $guarded = [];

    protected $casts = [
        'amount'          => 'decimal:8',
        'original_amount' => 'decimal:8',
        'occurred_at'     => 'datetime',
        'meta'            => 'array',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(VirtualCard::class, 'virtual_card_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Money that left the card, as a purchase or a withdrawal. */
    public function isOutgoing(): bool
    {
        return in_array($this->kind, [self::KIND_PURCHASE, self::KIND_WITHDRAW, self::KIND_FEE], true);
    }
}
