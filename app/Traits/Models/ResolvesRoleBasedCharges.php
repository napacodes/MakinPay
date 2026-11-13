<?php

declare(strict_types=1);

namespace App\Traits\Models;

use App\Models\User;
use App\Support\FeeCalculator;

/**
 * Role-aware charge resolution shared by DepositMethod and WithdrawMethod.
 *
 * Both models carried a byte-identical copy of this logic, and both copies had
 * the same latent bug: the agent branch of the *type* accessor gated on the
 * presence of `agent_charge` (the amount) rather than `agent_charge_type`, so
 * an agent with a type but no amount silently fell through to the user type.
 * Resolving the role once and reading every component from that one prefix
 * removes the whole class of mismatch.
 */
trait ResolvesRoleBasedCharges
{
    /**
     * Every charge column family on the model.
     *
     * @var list<string>
     */
    protected static array $chargeFamilies = ['charge', 'user_charge', 'merchant_charge', 'agent_charge'];

    /**
     * Keep the legacy amount + type pair and the fixed/percent pair in sync in
     * whichever direction was written.
     *
     * Without this, anything still writing only the legacy columns — an older
     * cached Blade, a customer customisation, an external POST — would leave
     * the new columns at their zero default and every fee would silently
     * become zero.
     */
    protected static function bootResolvesRoleBasedCharges(): void
    {
        static::saving(function ($model): void {
            foreach (static::$chargeFamilies as $family) {
                $fixed   = $family.'_fixed';
                $percent = $family.'_percent';
                $type    = $family.'_type';

                $attributes = $model->getAttributes();

                // `_fixed` and `_percent` are NOT NULL DEFAULT 0, but a blank
                // form field reaches the model as null — ConvertEmptyStringsToNull
                // turns '' into null before validation ever sees it, and
                // `nullable` lets it through. An unconfigured component simply
                // means zero, so settle it here rather than letting the insert
                // fail on a constraint. `_min` / `_max` are genuinely nullable
                // ("no floor" differs from a floor of zero) and are left alone.
                foreach ([$fixed, $percent] as $component) {
                    if (array_key_exists($component, $attributes) && $attributes[$component] === null) {
                        $model->attributes[$component] = 0;
                        $attributes[$component]        = 0;
                    }
                }

                $fixedValue   = (float) ($attributes[$fixed] ?? 0);
                $percentValue = (float) ($attributes[$percent] ?? 0);
                $isConfigured = $fixedValue > 0 || $percentValue > 0;

                // A legacy write is only trusted when no component is
                // configured. Deciding this on isDirty() was the bug: the admin
                // form reposts the exact stored decimal, so the components look
                // unchanged while a stale legacy default looks like an edit —
                // and the charge got zeroed. A legacy write must never be able
                // to clear a configured component.
                if (! $isConfigured && $model->isDirty([$family, $type])) {
                    $legacyType = $attributes[$type] ?? null;

                    if ($legacyType instanceof \BackedEnum) {
                        $legacyType = $legacyType->value;
                    }

                    $split = FeeCalculator::fromLegacy(
                        $legacyType === null ? null : (string) $legacyType,
                        $attributes[$family] ?? 0,
                    );

                    $model->attributes[$fixed]   = $split['fixed'];
                    $model->attributes[$percent] = $split['percent'];

                    $fixedValue   = (float) $split['fixed'];
                    $percentValue = (float) $split['percent'];
                    $isConfigured = $fixedValue > 0 || $percentValue > 0;
                }

                // The components are the source of truth for the legacy pair,
                // so those columns can never drift or be left unset.
                $model->attributes[$type] = FeeCalculator::toLegacyType($fixedValue, $percentValue);

                if ($isConfigured) {
                    $model->attributes[$family] = $fixedValue > 0 ? $fixedValue : $percentValue;

                    continue;
                }

                // Nothing configured. The base `charge` column is NOT NULL, so
                // it settles at 0; a role override stays null, which is what
                // makes it fall back to the regular user charge.
                $model->attributes[$family] = $family === 'charge'
                    ? ($attributes[$family] ?? 0)
                    : ($attributes[$family] ?? null);
            }
        });
    }

    /**
     * Which column prefix applies to the current viewer.
     *
     * Admins and guests always see the base configuration; only an
     * authenticated `User` gets the merchant/agent/user overrides.
     */
    protected function chargePrefix(): string
    {
        $user = auth()->user();

        return $this->chargePrefixFor($user instanceof User ? $user : null);
    }

    /**
     * Which column prefix applies to this account, whoever is signed in.
     *
     * A mass payout is priced for the batch owner, and the confirm, the
     * approval and the background run may each happen under a different
     * session (or none), so the owner is pinned explicitly rather than read
     * from `auth()`. A null account gets the base configuration, as an admin
     * or a guest does above.
     */
    public function chargePrefixFor(?User $user): string
    {
        if ($user === null) {
            return '';
        }

        if ($user->isMerchant() && $this->hasChargeOverride('merchant_charge')) {
            return 'merchant_';
        }

        if ($user->isAgent() && $this->hasChargeOverride('agent_charge')) {
            return 'agent_';
        }

        if ($this->hasChargeOverride('user_charge')) {
            return 'user_';
        }

        return '';
    }

    /**
     * A role override counts as configured when its amount is set, or when
     * either of its new components is non-zero.
     *
     * The amount check preserves the original semantics — a null
     * `agent_charge` means "no agent-specific pricing", and must still fall
     * back to the user charge. The component check is what lets a role priced
     * purely as a percentage in the new columns be recognised.
     *
     * Deliberately not keyed off `_type`: that column is always populated
     * (it is a non-null enum), so testing it would make every role look
     * configured and break the fallback chain entirely.
     */
    protected function hasChargeOverride(string $base): bool
    {
        if (($this->attributes[$base] ?? null) !== null) {
            return true;
        }

        foreach ([$base.'_fixed', $base.'_percent'] as $column) {
            if ((float) ($this->attributes[$column] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every charge component that applies to this account, read from one
     * prefix, as decimal strings. Fixed and percent fall back to '0' and the
     * min/max caps stay null when unset, exactly as the `charge_*` accessors
     * report them for the signed-in user.
     *
     * @return array{prefix: string, fixed: string, percent: string, min: string|null, max: string|null}
     */
    public function chargeComponentsFor(User $user): array
    {
        $prefix = $this->chargePrefixFor($user);

        return [
            'prefix'  => $prefix,
            'fixed'   => $this->chargeColumn($prefix, '_fixed')   ?? '0',
            'percent' => $this->chargeColumn($prefix, '_percent') ?? '0',
            'min'     => $this->chargeColumn($prefix, '_min'),
            'max'     => $this->chargeColumn($prefix, '_max'),
        ];
    }

    protected function chargeComponent(string $suffix): ?string
    {
        return $this->chargeColumn($this->chargePrefix(), $suffix);
    }

    private function chargeColumn(string $prefix, string $suffix): ?string
    {
        $column = $prefix.'charge'.$suffix;

        return isset($this->attributes[$column]) ? (string) $this->attributes[$column] : null;
    }

    public function getChargeFixedAttribute(): string
    {
        return $this->chargeComponent('_fixed') ?? '0';
    }

    public function getChargePercentAttribute(): string
    {
        return $this->chargeComponent('_percent') ?? '0';
    }

    public function getChargeMinAttribute(): ?string
    {
        return $this->chargeComponent('_min');
    }

    public function getChargeMaxAttribute(): ?string
    {
        return $this->chargeComponent('_max');
    }

    /**
     * @deprecated Use `calculateCharge()`, or read `charge_fixed` +
     *             `charge_percent` directly.
     *
     * The legacy single-value charge. For a method configured the old way
     * (one component only) this returns exactly what it always did.
     *
     * It CANNOT represent a method that uses both components: with
     * `0.30 + 2.9%` this returns 0.30 while the real charge on 200 is 6.10.
     * A single number has no way to express the pair, which is why the two
     * columns exist. Never compute a fee from this value.
     */
    public function getChargeAttribute(): float
    {
        $fixed = (float) $this->charge_fixed;

        return $fixed > 0 ? $fixed : (float) $this->charge_percent;
    }

    /**
     * @deprecated The fee is no longer one type or the other.
     *
     * Kept so the enum column stays valid and old readers do not break, but a
     * method using both components reports 'fixed' and understates the charge.
     * See {@see getChargeAttribute()}.
     */
    public function getChargeTypeAttribute(): string
    {
        return FeeCalculator::toLegacyType($this->charge_fixed, $this->charge_percent);
    }

    /**
     * Total charge for an amount: fixed + percentage, within any configured
     * floor and ceiling.
     */
    /**
     * Charge for an amount given in the platform's base currency.
     *
     * The fixed component and the min/max caps are denominated in the method's
     * own currency — which is exactly what the admin form labels them with
     * ("Fixed 0.30 EUR"), and how processors publish their fee schedules. The
     * arithmetic therefore runs in the method's currency and the result is
     * expressed back in base, which is the unit every caller here works in.
     *
     * Takes and returns decimal strings (a float is accepted and read at its
     * own precision), so the fee reaches the ledger exact (CR1).
     */
    public function calculateCharge(string|int|float $amount): string
    {
        return app(FeeCalculator::class)->totalFromBase(
            $amount,
            $this->conversion_rate,
            $this->charge_fixed,
            $this->charge_percent,
            $this->charge_min,
            $this->charge_max,
        );
    }
}
