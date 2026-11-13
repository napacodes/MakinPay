<?php

namespace App\Models;

use App\Enums\AmountFlow;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Notifications\TransactionStatusChangedNotification;
use App\Services\QRCodeService;
use App\Support\DecimalMoney;
use App\Support\Money;
use App\Traits\Models\StoresDecimalsExactly;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A ledger entry.
 *
 * Two generations of money columns live here, and it matters which pair you
 * read:
 *
 *  - `amount` / `fee` / `currency` are the original columns. `currency` names
 *    the unit `amount` is in, which is the base currency for flows that
 *    convert a customer-entered figure and the wallet's own currency for
 *    operations that already work in it. Correct, but it takes reading the
 *    writer to know which. `net_amount` / `payable_amount` are in the wallet's
 *    currency regardless, so a single row legitimately holds two units.
 *
 *  - `exchange_rate`, `base_amount`, `base_fee`, `base_currency`,
 *    `wallet_amount`, `wallet_fee` are the FX snapshot (IAS 21) and are
 *    unambiguous: both sides are named, and the rate that connects them is
 *    recorded. Prefer these for anything that reports, reconciles or restates.
 *
 * The originals are kept because customer code and API consumers read them.
 * A null snapshot means the row predates the columns and its rate is not
 * recoverable; `money:audit-precision` reports those.
 */
class Transaction extends Model
{
    use HasFactory;
    use StoresDecimalsExactly;

    /** The remark a sandbox payment's payer leg is written with. */
    public const SANDBOX_REMARK = 'SANDBOX_TRANSACTION';

    // Automatically load the related 'user' relationship when querying this model
    protected $with = ['user'];

    protected $appends = ['currency_symbol'];

    // Fields that can be mass-assigned
    protected $fillable = [
        'user_id',
        'trx_id',
        'trx_type',
        'description',
        'provider',
        'gateway_code',
        'processing_type',
        'amount',
        'amount_flow',
        'fee',
        'currency',
        'net_amount',
        'payable_amount',
        'payable_currency',
        'wallet_reference',
        'trx_reference',
        'trx_data',
        'trx_token',
        'expires_at',
        'remarks',
        'status',
        'exchange_rate',
        'base_amount',
        'base_fee',
        'base_currency',
        'wallet_amount',
        'wallet_fee',
        'entered_amount',
        'entered_currency',
        'wallet_tax',
        'base_tax',
        'tax_breakdown',
    ];

    // Attribute casting for specific fields
    protected $casts = [
        'trx_type'        => TrxType::class,
        'processing_type' => MethodType::class,
        'status'          => TrxStatus::class,
        'amount_flow'     => AmountFlow::class,
        'trx_data'        => 'array',
        'expires_at'      => 'datetime',
        // Money is read back at the column's own scale, not as a float.
        // A float cannot hold decimal(24,8) at the top of its range, and
        // rounding the ledger is what let balances and history diverge.
        'fee'            => 'decimal:8',
        'amount'         => 'decimal:8',
        'net_amount'     => 'decimal:8',
        'payable_amount' => 'decimal:8',
        // FX snapshot. The rate keeps its own scale — it is not an amount.
        'exchange_rate' => 'decimal:18',
        'base_amount'   => 'decimal:8',
        'base_fee'      => 'decimal:8',
        'wallet_amount' => 'decimal:8',
        'wallet_fee'    => 'decimal:8',
        // What the customer typed. Display only — nothing computes from it.
        'entered_amount' => 'decimal:8',
        // GF6: the tax inside payable_amount, apart from the fee.
        'wallet_tax'    => 'decimal:8',
        'base_tax'      => 'decimal:8',
        'tax_breakdown' => 'array',
    ];

    // Default attribute values
    protected $attributes = [
        'fee'      => 0,
        'status'   => TrxStatus::PENDING,
        'provider' => 'system',
    ];

    /**
     * Total amount already refunded against this payment.
     *
     * The refund transactions themselves are the single source of truth — each
     * REFUND row points back here via `trx_reference`, and its `amount` is the
     * refunded value. We sum them instead of tracking a denormalized column so
     * the ledger can never drift out of sync.
     *
     * Quantized at this row's own currency, not at two decimals. A merchant
     * payment received in BTC is denominated in BTC — `currency` says so — and
     * rounding 0.00094118 to cents gave 0.00, so `refundableAmount()` came out
     * at zero and every crypto payment reported itself as already fully
     * refunded. The reverse was just as wrong: a partial refund of 0.005 BTC
     * rounded up to 0.01, twice what was asked for.
     */
    public function refundedAmount(): float
    {
        return (float) $this->quantizeToOwnCurrency(
            (string) $this->refunds()->where('status', TrxStatus::COMPLETED->value)->sum('amount')
        );
    }

    /**
     * Amount of this received payment that can still be refunded.
     */
    public function refundableAmount(): float
    {
        $remaining = DecimalMoney::subtract(
            (string) $this->amount,
            (string) $this->refundedAmount(),
            DecimalMoney::CALCULATION_SCALE,
        );

        return max(0, (float) $this->quantizeToOwnCurrency($remaining));
    }

    /**
     * Round a value to the precision of the currency this row is denominated
     * in, falling back to the site default when the column is empty.
     */
    public function quantizeToOwnCurrency(string|int|float|null $value): string
    {
        return Money::quantize($value, $this->currency ?: null);
    }

    /**
     * The amount as a notification reads it: "100.00 NGN".
     *
     * `amount` is decimal(24,8), so concatenating it straight into a template
     * told a customer their "Withdraw 100.00000000 NGN" was rejected.
     */
    public function notificationAmount(): string
    {
        return trim(Money::format($this->amount, $this->currency ?: null, false).' '.$this->currency);
    }

    /**
     * The currency the wallet side of this row moves in, or null when the
     * writer did not name one.
     *
     * Never falls back to the base currency: on a base-denominated row that
     * would quantize a crypto payout at two places and write 0.00000941 BTC
     * away as 0. A wallet figure whose unit is unknown is left at ledger scale.
     */
    private function walletCurrencyCode(): ?string
    {
        $code = $this->attributes['payable_currency'] ?? null;

        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    /**
     * Whether the full credited amount of this payment has been refunded.
     */
    public function isFullyRefunded(): bool
    {
        return $this->refundedAmount() > 0 && $this->refundableAmount() <= 0;
    }

    /**
     * Whether some — but not all — of this payment has been refunded.
     */
    public function isPartiallyRefunded(): bool
    {
        return $this->refundedAmount() > 0 && $this->refundableAmount() > 0;
    }

    // Relationships

    /**
     * Automatically generate a unique transaction ID (trx_id) before creating a transaction.
     */
    public static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (! $transaction->trx_id) {
                do {
                    $transaction->trx_id = 'TXN'.Str::upper(Str::random(12));
                } while (self::where('trx_id', $transaction->trx_id)->exists());
            }

            $transaction->completeFxSnapshot();
        });

        // "Notify me when it lands": only rows the customer asked about.
        static::updated(function ($transaction) {
            if ($transaction->wasChanged('status') && data_get($transaction->trx_data, 'notify_on_status') === true && $transaction->user) {
                $transaction->user->notify(new TransactionStatusChangedNotification($transaction));
            }
        });
    }

    /**
     * Fill in whichever half of the FX snapshot the writer left out.
     *
     * Deliberately conservative. The reporting currency is always safe to
     * stamp — it is simply what the platform's base was at this instant. The
     * converted sides are only derived when the caller supplied an explicit
     * rate, because a rate we were not given is a financial fact we do not
     * have. A row with no rate keeps a NULL snapshot on purpose;
     * `money:audit-precision` reports those rather than inventing them.
     *
     * Which side `amount` is already on is read from `currency`, which names
     * the unit `amount` is denominated in. Writers split cleanly in two:
     * transfers that take a base-currency figure from the customer and convert
     * it (`currency: siteCurrency()`), and operations that already work in the
     * wallet's own currency (`currency: $wallet->currency->code`). Multiplying
     * by the rate in the second case would convert an amount that was never in
     * base currency to begin with.
     *
     * A caller that has both sides to hand should pass them; anything passed
     * explicitly is left untouched.
     */
    public function completeFxSnapshot(): void
    {
        if ($this->base_currency === null) {
            $this->base_currency = siteCurrency();
        }

        $rate = $this->attributes['exchange_rate'] ?? null;

        if ($rate === null || ! is_numeric($rate) || (float) $rate <= 0) {
            return;
        }

        $rate   = DecimalMoney::rate($rate);
        $amount = (string) ($this->attributes['amount'] ?? 0);
        $fee    = (string) ($this->attributes['fee'] ?? 0);

        // Derived in decimal strings end to end. These used to be cast to
        // float on the way in, which bound them at `precision` 14 and cut a
        // digit off any figure of fifteen or more - exactly the loss the
        // snapshot exists to prevent.
        if ($this->isDenominatedInBaseCurrency()) {
            $this->base_amount ??= $amount;
            $this->base_fee    ??= $fee;

            // The wallet side is money that actually moves, so when the row
            // names the unit it moves in, it lands on that currency's own
            // precision — the same place PaymentService puts the figures it
            // hands over explicitly. Derived and supplied rows would otherwise
            // disagree: a 1% fee on 20 base at 0.92 came out as 0.184 EUR here
            // and 0.18 EUR there. The base side keeps calculation scale; it is
            // the reporting equivalent, not a payment.
            $walletCurrency = $this->walletCurrencyCode();

            $onWalletScale = fn (string $value): string => $walletCurrency === null
                ? $value
                : Money::quantize($value, $walletCurrency);

            $this->wallet_amount ??= $onWalletScale(DecimalMoney::multiply((string) $this->base_amount, $rate));
            $this->wallet_fee    ??= $onWalletScale(DecimalMoney::multiply((string) $this->base_fee, $rate));

            return;
        }

        $this->wallet_amount ??= $amount;
        $this->wallet_fee    ??= $fee;
        $this->base_amount   ??= DecimalMoney::toBase((string) $this->wallet_amount, $rate);
        $this->base_fee      ??= DecimalMoney::toBase((string) $this->wallet_fee, $rate);
    }

    /**
     * Whether `amount` is expressed in the reporting currency rather than the
     * wallet's own.
     */
    private function isDenominatedInBaseCurrency(): bool
    {
        $currency = strtoupper(trim((string) ($this->attributes['currency'] ?? '')));

        return $currency === '' || $currency === strtoupper(trim((string) $this->base_currency));
    }

    /**
     * Determine if the transaction QR is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && now()->greaterThan($this->expires_at);
    }

    /**
     * Scope: Find transaction by QR token
     */
    public function scopeByTrxToken($query, string $token)
    {
        return $query->where('trx_token', $token);
    }

    public function getQrCodeSvgAttribute()
    {
        if (! $this->trx_token) {
            return '';
        }

        $qrService = app(QRCodeService::class);

        $url = route('payment.pay', [
            'merchant' => $this->trx_data['merchant_name'],
            'token'    => $this->trx_token,
        ]);

        return $qrService->generate($url);
    }

    /**
     * Get formatted expiry time (optional)
     */
    public function formattedExpiresAt(): ?string
    {
        return $this->expires_at ? $this->expires_at->format('Y-m-d H:i') : null;
    }

    /**
     * Transaction belongs to a user.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Refund transactions issued against this payment.
     *
     * Each REFUND row references this transaction by `trx_id` through its own
     * `trx_reference`, so the refunds read straight from the ledger.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'trx_reference', 'trx_id')
            ->where('trx_type', TrxType::REFUND->value);
    }

    /**
     * Every webhook the platform owed a merchant about this payment, and
     * whether it got there.
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(MerchantWebhookDelivery::class);
    }

    /**
     * Unified filter scope for modular query building.
     */
    public function scopeApplyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['trx_type'] ?? null, fn ($q, $type) => $q->whereIn('trx_type', (array) $type))
            ->when($filters['processing_type'] ?? null, fn ($q, $processingType) => $q->where('processing_type', $processingType))
            ->when($filters['provider'] ?? null, fn ($q, $provider) => $q->where('provider', $provider))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, function ($q, $searchTerm) {
                $q->where(function ($q) use ($searchTerm) {
                    $q->where('trx_type', 'like', "%{$searchTerm}%")
                        ->orWhere('description', 'like', "%{$searchTerm}%")
                        ->orWhere('trx_id', 'like', "%{$searchTerm}%")
                        ->orWhereHas('user', function ($q) use ($searchTerm) {
                            $q->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchTerm}%"]);
                        });
                });
            })
            ->when($filters['dateRange'] ?? null, function ($q, $dateRange) {
                $dateRange = explode(',', $dateRange);
                if (count($dateRange) !== 2) {
                    return;
                }
                [$startDate, $endDate] = $dateRange;
                $q->whereBetween('created_at', [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay(),
                ]);
            });
    }

    /**
     * Rows a sandbox (test-mode) payment wrote. Their wallets were never
     * debited or credited - PaymentHandler skips the money by design - so a
     * figure that sums the ledger has to leave them out or count money that
     * never moved. Three markers, one per writer: the payer's leg carries the
     * remark, a checkout row the environment, the receive flows is_sandbox.
     *
     * Each test is guarded with its own not-null so that a row with no remark
     * or no trx_data reads as false rather than SQL NULL: withoutSandbox()
     * negates this, and NOT NULL would drop every ordinary row.
     */
    public function scopeSandbox(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->whereNotNull('remarks')->where('remarks', 'like', '%'.self::SANDBOX_REMARK.'%'))
            ->orWhere(fn (Builder $w) => $w->whereNotNull('trx_data->is_sandbox')->where('trx_data->is_sandbox', true))
            ->orWhere(fn (Builder $w) => $w->whereNotNull('trx_data->environment')->where('trx_data->environment', 'sandbox')));
    }

    /**
     * Rows whose money really moved; see scopeSandbox. For totals - the rows
     * themselves stay in lists, where a test payment is useful to see.
     */
    public function scopeWithoutSandbox(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $q) => $q->sandbox());
    }

    /**
     * The row-level reading of scopeSandbox.
     */
    public function isSandbox(): bool
    {
        $data = (array) ($this->trx_data ?? []);

        return str_contains((string) $this->remarks, self::SANDBOX_REMARK)
            || ! empty($data['is_sandbox'])
            || ($data['environment'] ?? null) === 'sandbox';
    }

    // Accessors

    /**
     * Count the total amount of transactions for a given type and status.
     */
    public function scopeCountAmount(Builder $query, string $transactionType, string $status): float
    {
        return $query->where('trx_type', $transactionType)->where('status', $status)->sum('amount');
    }

    // Scope to filter transactions from the last X days.
    public function scopeRecent($query, $days = 7)
    {
        return $query->whereDate('created_at', '>=', now()->subDays($days));
    }

    // Scope to filter transactions by a given status.
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    // Scope to filter transactions that are either failed or canceled.
    public function scopeFailedOrCanceled($query)
    {
        return $query->where(function ($q) {
            $q->where('status', TrxStatus::FAILED)
                ->orWhere('status', TrxStatus::CANCELED);
        });
    }

    /**
     * Format the created_at attribute to a readable format.
     */
    public function getCreatedAtTimeAttribute(): string
    {
        return Carbon::parse($this->attributes['created_at'])->format('M d Y h:i A');
    }

    public function getCurrencySymbolAttribute(): ?string
    {
        return getSymbol($this->currency);
    }
}
