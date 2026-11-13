<?php

namespace App\Data;

use App\Enums\AmountFlow;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use InvalidArgumentException;

/**
 * One transaction row as a writer hands it over.
 *
 * Every money field takes a decimal string, an int or a float, and keeps
 * what it was given. The string form is the exact one - DecimalMoney works
 * in it and the columns are decimal(24,8) - and is what every flow should
 * hand over. A float is tolerated so the older writers keep working; the
 * Transaction model converts it to its exact decimal string before the row
 * is bound (StoresDecimalsExactly), so no digit is lost on the way to the
 * column. Nothing in here narrows a string to a double.
 *
 * A string that is not a number is refused here, at construction, with the
 * field named - never passed on to the row. The request layer validates
 * what a customer types (AmountEntry::rules); this is the guard for every
 * writer behind it.
 */
class TransactionData
{
    public int $user_id;

    public TrxType $trx_type;

    public string|int|float $amount;

    public AmountFlow $amount_flow;

    public string|int|float|null $fee;

    public ?string $currency;

    public ?string $provider;

    public MethodType $processing_type;

    public string|int|float|null $net_amount;

    public string|int|float|null $payable_amount;

    public ?string $payable_currency;

    public ?string $wallet_reference;

    public ?string $trx_reference;

    public ?array $trx_data;

    public ?string $remarks;

    public ?string $description;

    public TrxStatus $status;

    public ?string $trx_token;

    public ?\DateTimeInterface $expires_at;

    /**
     * Rate applied at the moment of the entry: 1 unit of base currency buys
     * this many units of the account currency. Kept as a string because
     * decimal(36,18) exceeds what a float can hold without loss.
     */
    public ?string $exchange_rate;

    public string|int|float|null $base_amount;

    public string|int|float|null $base_fee;

    /**
     * The reporting currency at the time of the entry. Stored per row because
     * the platform's base currency can be rebased later.
     */
    public ?string $base_currency;

    public string|int|float|null $wallet_amount;

    public string|int|float|null $wallet_fee;

    /**
     * What the customer typed, in the unit they typed it in.
     *
     * `amount` is base-denominated because the ledger, the FX snapshot and
     * every existing consumer read it that way. The forms now take the wallet's
     * own currency and convert at the entry point, so these two record the
     * original input for receipts and disputes. Nothing computes from them.
     */
    public string|int|float|null $entered_amount;

    public ?string $entered_currency;

    /** GF6: the tax in the payable currency, inside payable_amount (decimal string), or null. */
    public ?string $wallet_tax;

    /** GF6: the same tax in the site currency, for reporting (decimal string), or null. */
    public ?string $base_tax;

    /** GF6: one entry per tax rule that applied, or null. */
    public ?array $tax_breakdown;

    /**
     * Constructor to initialize transaction data aligned with the transactions table schema.
     *
     * The FX snapshot arguments are last, and `copy()` rebuilds this object
     * positionally from get_object_vars() — so property declaration order and
     * parameter order must stay identical.
     */
    public function __construct(
        int $user_id,
        TrxType $trx_type,
        string|int|float $amount,
        AmountFlow $amount_flow = AmountFlow::DEFAULT,
        string|int|float|null $fee = 0,
        ?string $currency = null,
        ?string $provider = null,
        MethodType $processing_type = MethodType::AUTOMATIC,
        string|int|float|null $net_amount = 0,
        string|int|float|null $payable_amount = null,
        ?string $payable_currency = null,
        ?string $wallet_reference = null,
        ?string $trx_reference = null,
        ?array $trx_data = null,
        ?string $remarks = null,
        ?string $description = '',
        TrxStatus $status = TrxStatus::PENDING,
        ?string $trx_token = null,
        ?\DateTimeInterface $expires_at = null,
        ?string $exchange_rate = null,
        string|int|float|null $base_amount = null,
        string|int|float|null $base_fee = null,
        ?string $base_currency = null,
        string|int|float|null $wallet_amount = null,
        string|int|float|null $wallet_fee = null,
        string|int|float|null $entered_amount = null,
        ?string $entered_currency = null,
        ?string $wallet_tax = null,
        ?string $base_tax = null,
        ?array $tax_breakdown = null,
    ) {
        $this->user_id          = $user_id;
        $this->trx_type         = $trx_type;
        $this->amount           = self::money($amount, 'amount');
        $this->amount_flow      = $amount_flow;
        $this->fee              = self::money($fee, 'fee');
        $this->currency         = $currency ?? config('app.default_currency');
        $this->provider         = $provider;
        $this->processing_type  = $processing_type;
        $this->net_amount       = self::money($net_amount, 'net_amount');
        $this->payable_amount   = self::money($payable_amount, 'payable_amount');
        $this->payable_currency = $payable_currency;
        $this->wallet_reference = $wallet_reference;
        $this->trx_reference    = $trx_reference;
        $this->trx_data         = $trx_data;
        $this->remarks          = $remarks;
        $this->description      = $description;
        $this->status           = $status;
        $this->trx_token        = $trx_token;
        $this->expires_at       = $expires_at;
        $this->exchange_rate    = $exchange_rate;
        $this->base_amount      = self::money($base_amount, 'base_amount');
        $this->base_fee         = self::money($base_fee, 'base_fee');
        $this->base_currency    = $base_currency;
        $this->wallet_amount    = self::money($wallet_amount, 'wallet_amount');
        $this->wallet_fee       = self::money($wallet_fee, 'wallet_fee');
        $this->entered_amount   = self::money($entered_amount, 'entered_amount');
        $this->entered_currency = $entered_currency;
        $this->wallet_tax       = self::money($wallet_tax, 'wallet_tax');
        $this->base_tax         = self::money($base_tax, 'base_tax');
        $this->tax_breakdown    = $tax_breakdown;
    }

    /**
     * A money value as given, once it is known to be a number.
     *
     * A string is trimmed and must be numeric; "", "abc" or "12.3.4" is
     * refused by name instead of reaching a decimal column as 0 or as text.
     * Ints and floats are numbers already and pass through.
     *
     * @throws InvalidArgumentException
     */
    private static function money(string|int|float|null $value, string $field): string|int|float|null
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Transaction %s must be a decimal number, got "%s".', $field, $value));
        }

        return $value;
    }

    /**
     * Creates a copy of the current TransactionData with overridden properties.
     *
     * @param array $overrides Associative array of properties to override.
     */
    public function copy(array $overrides = []): TransactionData
    {
        $properties       = get_object_vars($this); // Get all current properties
        $mergedProperties = array_merge($properties, $overrides); // Merge overrides

        return new self(...array_values($mergedProperties));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
