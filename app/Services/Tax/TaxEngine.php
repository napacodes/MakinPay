<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Enums\Tax\TaxableType;
use App\Enums\Tax\TaxBase;
use App\Models\TaxRule;
use App\Models\User;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Works out the tax on one transaction from the admin's rules (GF6).
 *
 * Every active rule for the transaction type whose scope covers the customer
 * applies, and the taxes add up, each kept as its own line. A rule's line is
 *
 *     percent% of its base (the amount, or the fee)  +  fixed (site currency, at the rate)
 *
 * where a rule charged by amount brackets takes the percent and fixed part
 * of the bracket the amount falls in (see {@see TaxRule::brackets()}).
 *
 * worked out at the calculation scale and then CUT, never rounded, to the
 * currency's own places, exactly as a fee is cut. The total is the sum of the
 * cut lines, so the lines always add up to it.
 *
 * Decimal strings only. The amount and the fee are in the currency the
 * customer pays in; `$rate` is how many of that currency one unit of the site
 * currency is worth, the rate a fixed part is converted at and the total is
 * restated to the site currency with.
 *
 * The tax is charged on top of what the customer pays: a flow adds the
 * total to its payable and leaves the amount and the fee as they were. With
 * no rule that applies, or no `tax_rules` table yet (an upgrade before its
 * migration ran), the quote is empty and nothing changes.
 */
class TaxEngine
{
    /** Whether the rules table exists, asked once per engine. */
    private ?bool $hasTable = null;

    /**
     * The active rules per type, read once per engine and dropped whenever a
     * rule is saved or deleted (TaxRule bumps the version), so a screen that
     * lists many methods asks once instead of once per method.
     *
     * @var array<string, list<TaxRule>>
     */
    private array $rulesByType = [];

    private int $rulesVersion = -1;

    private static int $version = 0;

    /** Forget every engine's cached rules; called when a rule changes. */
    public static function forgetRules(): void
    {
        self::$version++;
    }

    /**
     * @throws InvalidArgumentException for an amount, fee or rate that is not a decimal
     */
    public function quote(TaxableType $type, User $user, string $currency, string $amount, string $fee, string $rate): TaxQuote
    {
        $currency = strtoupper(trim($currency));
        $decimals = Money::decimals($currency);

        foreach (['amount' => $amount, 'fee' => $fee, 'rate' => $rate] as $name => $value) {
            if (! is_numeric($value)) {
                throw new InvalidArgumentException("The {$name} a tax is worked out on must be a decimal.");
            }
        }

        $rules = $this->rulesFor($type, $user);

        if ($rules === []) {
            return TaxQuote::none($currency, $decimals);
        }

        $lines = [];
        $total = DecimalMoney::normalize('0', $decimals);

        foreach ($rules as $rule) {
            $charge = $this->chargeFor($rule, $amount, $rate);

            if ($charge === null) {
                continue;
            }

            $base    = $rule->base() === TaxBase::Fee ? $fee : $amount;
            $percent = $charge['percent'];
            $fixed   = $charge['fixed'];

            $share = DecimalMoney::percentage($this->nonNegative($base), $percent, DecimalMoney::CALCULATION_SCALE);
            $share = DecimalMoney::add($share, DecimalMoney::multiply($fixed, $rate, DecimalMoney::CALCULATION_SCALE), DecimalMoney::CALCULATION_SCALE);
            $share = DecimalMoney::normalize($share, $decimals);

            if (DecimalMoney::compare($share, '0') <= 0) {
                continue;
            }

            $lines[] = new TaxLine(
                ruleId: (int) $rule->id,
                name: (string) $rule->name,
                percent: DecimalMoney::normalize($percent, 4),
                fixed: DecimalMoney::normalize($fixed, DecimalMoney::CALCULATION_SCALE),
                base: (string) $rule->base()?->value,
                amount: $share,
            );

            $total = DecimalMoney::add($total, $share, $decimals);
        }

        if ($lines === []) {
            return TaxQuote::none($currency, $decimals);
        }

        $baseTotal = DecimalMoney::compare($rate, '0', DecimalMoney::RATE_SCALE) > 0
            ? DecimalMoney::divide($total, $rate, DecimalMoney::CALCULATION_SCALE)
            : DecimalMoney::normalize($total, DecimalMoney::CALCULATION_SCALE);

        return new TaxQuote($currency, $decimals, $lines, $total, $baseTotal);
    }

    /**
     * The rules a web preview mirrors in the browser, for this customer and
     * type, with the fixed part (and every bracket's limit and fixed part)
     * already converted at the rate into the currency the customer pays in.
     * `brackets` is empty for a rule with one rate for every amount. The
     * preview is advice; the charge is always {@see self::quote()} on the
     * server.
     *
     * @return list<array{name: string, percent: string, fixed: string, base: string, brackets: list<array{up_to: ?string, percent: string, fixed: string}>}>
     */
    public function previewRules(TaxableType $type, User $user, string $rate): array
    {
        $rate = is_numeric($rate) ? $rate : '0';

        return array_map(fn (TaxRule $rule): array => [
            'name'     => (string) $rule->name,
            'percent'  => $this->nonNegative((string) $rule->percent),
            'fixed'    => DecimalMoney::multiply($this->nonNegative((string) $rule->fixed_amount), $rate, DecimalMoney::CALCULATION_SCALE),
            'base'     => (string) $rule->base()?->value,
            'brackets' => array_map(fn (array $bracket): array => [
                'up_to'   => $bracket['up_to'] === null ? null : DecimalMoney::multiply($bracket['up_to'], $rate, DecimalMoney::CALCULATION_SCALE),
                'percent' => $this->nonNegative($bracket['percent']),
                'fixed'   => DecimalMoney::multiply($this->nonNegative($bracket['fixed']), $rate, DecimalMoney::CALCULATION_SCALE),
            ], $rule->brackets()),
        ], $this->rulesFor($type, $user));
    }

    /**
     * The percent and the fixed part (site currency) this rule charges on
     * this amount: its own for a rule with one rate, else the first bracket
     * whose limit, converted at the rate, the amount does not exceed. Null
     * when the amount is above a rule's last, capped bracket.
     *
     * @return array{percent: string, fixed: string}|null
     */
    private function chargeFor(TaxRule $rule, string $amount, string $rate): ?array
    {
        $brackets = $rule->brackets();

        if ($brackets === []) {
            return [
                'percent' => $this->nonNegative((string) $rule->percent),
                'fixed'   => $this->nonNegative((string) $rule->fixed_amount),
            ];
        }

        foreach ($brackets as $bracket) {
            $limit = $bracket['up_to'] === null ? null : DecimalMoney::multiply($bracket['up_to'], $rate, DecimalMoney::CALCULATION_SCALE);

            if ($limit === null || DecimalMoney::compare($amount, $limit) <= 0) {
                return [
                    'percent' => $this->nonNegative($bracket['percent']),
                    'fixed'   => $this->nonNegative($bracket['fixed']),
                ];
            }
        }

        return null;
    }

    /**
     * The active rules for the type that cover this customer, in display
     * order. A rule with a type, scope or base this code does not know is
     * skipped rather than guessed at.
     *
     * @return list<TaxRule>
     */
    public function rulesFor(TaxableType $type, User $user): array
    {
        $this->hasTable ??= Schema::hasTable('tax_rules');

        if (! $this->hasTable) {
            return [];
        }

        if ($this->rulesVersion !== self::$version) {
            $this->rulesByType  = [];
            $this->rulesVersion = self::$version;
        }

        $this->rulesByType[$type->value] ??= TaxRule::query()->activeFor($type)->get()->all();

        return collect($this->rulesByType[$type->value])
            ->filter(fn (TaxRule $rule): bool => $rule->scope()?->covers($user, $rule->countryCodes()) === true && $rule->base() !== null)
            ->values()
            ->all();
    }

    private function nonNegative(string $value): string
    {
        $value = is_numeric($value) ? DecimalMoney::normalize($value, DecimalMoney::CALCULATION_SCALE) : '0';

        return DecimalMoney::compare($value, '0') < 0 ? '0' : $value;
    }
}
