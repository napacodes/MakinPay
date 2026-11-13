<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Tax\TaxableType;
use App\Enums\Tax\TaxBase;
use App\Enums\Tax\TaxScope;
use App\Services\Tax\TaxEngine;
use App\Support\DecimalMoney;
use Database\Factories\TaxRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One tax or levy an admin defined (GF6).
 *
 * `percent` is a percentage of the rule's base, `fixed_amount` a flat amount
 * in the site currency; either or both may be set. Both are cast to exact
 * decimal strings and never read as floats.
 *
 * A rule with `brackets` charges by amount instead: the transaction amount
 * picks the first bracket whose `up_to` (site currency) it does not exceed,
 * and that bracket's percent and fixed part replace the rule's own. A null
 * `up_to` on the last bracket means "and above"; an amount above a capped
 * last bracket is not taxed by the rule. See {@see self::brackets()}.
 *
 * `transaction_type`, `scope` and `base` stay plain strings, read through
 * {@see self::type()}, {@see self::scope()} and {@see self::base()}. A rule
 * whose stored value this code does not know reads as null there, and the
 * tax engine skips it rather than guessing what it meant.
 */
class TaxRule extends Model
{
    /** @use HasFactory<TaxRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'transaction_type',
        'scope',
        'countries',
        'percent',
        'fixed_amount',
        'brackets',
        'base',
        'is_active',
        'sort_order',
    ];

    /**
     * A rule that changes drops every engine's cached rules, so the next
     * quote in this process reads what the administrator just saved.
     */
    protected static function booted(): void
    {
        static::saved(static fn (): mixed => TaxEngine::forgetRules());
        static::deleted(static fn (): mixed => TaxEngine::forgetRules());
    }

    protected function casts(): array
    {
        return [
            'countries'    => 'array',
            'percent'      => 'decimal:4',
            'fixed_amount' => 'decimal:8',
            'brackets'     => 'array',
            'is_active'    => 'boolean',
            'sort_order'   => 'integer',
        ];
    }

    public function type(): ?TaxableType
    {
        return TaxableType::tryFrom((string) $this->getAttribute('transaction_type'));
    }

    public function scope(): ?TaxScope
    {
        return TaxScope::tryFrom((string) $this->getAttribute('scope'));
    }

    public function base(): ?TaxBase
    {
        return TaxBase::tryFrom((string) $this->getAttribute('base'));
    }

    /**
     * The rule's amount brackets, lowest limit first, as exact decimal
     * strings. Empty for a rule with one rate for every amount. A malformed
     * row is dropped, and only the first open-ended bracket is kept, always
     * last: a second could never be reached.
     *
     * @return list<array{up_to: ?string, percent: string, fixed: string}>
     */
    public function brackets(): array
    {
        $stored = $this->getAttribute('brackets');

        if (! is_array($stored)) {
            return [];
        }

        $capped = [];
        $open   = null;

        foreach ($stored as $row) {
            if (! is_array($row)) {
                continue;
            }

            $upTo    = $row['up_to']   ?? null;
            $percent = $row['percent'] ?? '0';
            $fixed   = $row['fixed']   ?? '0';

            if (($upTo !== null && ! is_numeric($upTo)) || ! is_numeric($percent) || ! is_numeric($fixed)) {
                continue;
            }

            $bracket = [
                'up_to'   => $upTo === null ? null : DecimalMoney::normalize((string) $upTo, DecimalMoney::CALCULATION_SCALE),
                'percent' => DecimalMoney::normalize((string) $percent, 4),
                'fixed'   => DecimalMoney::normalize((string) $fixed, DecimalMoney::CALCULATION_SCALE),
            ];

            if ($upTo === null) {
                $open ??= $bracket;
            } else {
                $capped[] = $bracket;
            }
        }

        usort($capped, static fn (array $left, array $right): int => DecimalMoney::compare((string) $left['up_to'], (string) $right['up_to']));

        return $open === null ? $capped : [...$capped, $open];
    }

    public function hasBrackets(): bool
    {
        return $this->brackets() !== [];
    }

    /**
     * The rule's countries as upper-case ISO codes.
     *
     * @return list<string>
     */
    public function countryCodes(): array
    {
        $countries = $this->getAttribute('countries');

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $code): string => strtoupper(trim((string) $code)),
            is_array($countries) ? $countries : [],
        ), static fn (string $code): bool => preg_match('/^[A-Z]{2}$/', $code) === 1)));
    }

    /**
     * The active rules for one transaction type, in the order they are shown.
     *
     * @param Builder<TaxRule> $query
     */
    public function scopeActiveFor(Builder $query, TaxableType $type): Builder
    {
        return $query
            ->where('transaction_type', $type->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
