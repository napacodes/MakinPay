<?php

namespace App\Models\P2P;

use App\Enums\P2P\OrderStatus;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'p2p_orders';

    protected $fillable = [
        'order_number',
        'offer_id',
        'maker_id',
        'taker_id',
        'wallet_id',
        'payment_method_id',
        'payer_payment_account_id',
        'receiver_payment_account_id',
        'payer_payment_account_snapshot',
        'receiver_payment_account_snapshot',
        'price',
        'amount',
        'maker_fee',
        'taker_fee',
        'total',
        'status',
        'paid_at',
        'completed_at',
        'cancelled_at',
        'expired_at',
        'disputed_at',
        'expires_at',
        'trx_id',
        'remarks',
    ];

    protected $casts = [
        'price'                             => 'decimal:8',
        'amount'                            => 'decimal:8',
        'maker_fee'                         => 'decimal:8',
        'taker_fee'                         => 'decimal:8',
        'total'                             => 'decimal:8',
        'payer_payment_account_snapshot'    => 'array',
        'receiver_payment_account_snapshot' => 'array',
        'status'                            => OrderStatus::class,
        'paid_at'                           => 'datetime',
        'completed_at'                      => 'datetime',
        'cancelled_at'                      => 'datetime',
        'expired_at'                        => 'datetime',
        'disputed_at'                       => 'datetime',
        'expires_at'                        => 'datetime',
    ];

    /**
     * The shape of an order number: P2P- and ten characters drawn without
     * 0/O and 1/I, so a number read out to support cannot be misheard.
     * Random rather than sequential, because the row id it replaces in every
     * link told a customer how many orders the platform had taken and let
     * anyone walk the sequence.
     */
    public const NUMBER_PREFIX = 'P2P-';

    private const NUMBER_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const NUMBER_LENGTH = 10;

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (blank($order->order_number)) {
                $order->order_number = static::generateOrderNumber();
            }
        });
    }

    public static function generateOrderNumber(): string
    {
        do {
            $number = self::NUMBER_PREFIX;

            for ($i = 0; $i < self::NUMBER_LENGTH; $i++) {
                $number .= self::NUMBER_ALPHABET[random_int(0, strlen(self::NUMBER_ALPHABET) - 1)];
            }
        } while (static::withTrashed()->where('order_number', $number)->exists());

        return $number;
    }

    /**
     * Links carry the order number, never the row id.
     */
    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    /**
     * A link mailed before orders had numbers carries the row id, and it has
     * to keep opening the order. A number always starts with the prefix, so
     * an all-digit value can only be an id.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field === null && ctype_digit((string) $value)) {
            return $this->newQuery()->whereKey($value)->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taker_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function payerPaymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payer_payment_account_id');
    }

    public function receiverPaymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'receiver_payment_account_id');
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(OfferFeedback::class, 'order_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OrderMessage::class, 'order_id');
    }
}
