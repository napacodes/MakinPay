<?php

declare(strict_types=1);

namespace App\Traits\Models;

use App\Support\DecimalMoney;
use App\Support\Money;

/**
 * The range a customer can actually use, once both sets of limits apply.
 *
 * Two parties impose limits on the same payment: the platform states its own
 * policy in the base currency, and the processor states its own in the
 * currency it settles in. Neither overrides the other — the payment has to
 * satisfy both, so the usable range is the intersection.
 *
 * That is ordinary (a bank's daily cash limit and an ATM's per-withdrawal
 * limit compose the same way), but only if the customer is shown the range
 * that survives. Showing the platform's own figure when the processor caps it
 * lower invites someone to enter an amount that will be rejected — which is
 * worse than having no processor limit at all, because the rejection arrives
 * after they have committed to the flow.
 *
 * The figures returned here are in the base currency, matching the amount the
 * customer types. They are deliberately conservative: the server-side checks
 * remain authoritative, and a displayed bound that is a hair inside the real
 * one is safe, while one a hair outside is not.
 */
trait ResolvesEffectiveLimits
{
    /**
     * Lowest base-currency amount that satisfies both parties.
     */
    public function effectiveMinAmount(): string
    {
        $platform = DecimalMoney::normalize($this->{$this->platformMinColumn()} ?? 0);
        $provider = $this->providerBound($this->provider_min_amount, roundUp: true);

        $value = $provider !== null && bccomp($provider, $platform, 8) > 0 ? $provider : $platform;

        return $this->present($value, roundUp: true);
    }

    /**
     * Highest base-currency amount that satisfies both parties, or null when
     * neither party states a ceiling.
     */
    public function effectiveMaxAmount(): ?string
    {
        $platformRaw = $this->{$this->platformMaxColumn()} ?? null;
        $platform    = $platformRaw === null ? null : DecimalMoney::normalize($platformRaw);
        $provider    = $this->providerBound($this->provider_max_amount, roundUp: false);

        if ($provider === null && $platform === null) {
            return null;
        }

        $value = match (true) {
            $provider === null => $platform,
            $platform === null => $provider,
            default            => bccomp($provider, $platform, 8) < 0 ? $provider : $platform,
        };

        return $this->present($value, roundUp: false);
    }

    /**
     * A limit as the customer should see and type it.
     *
     * The internal arithmetic runs at ledger scale, but a USD limit rendered
     * as "100.00000000" is storage padding leaking onto the page — and it was,
     * because these figures replaced a plain float cast that had shown "100".
     * Quantized to the base currency's own precision, still away from the
     * boundary so the number stays one the server accepts, and stripped of
     * trailing zeros so it reads like a limit rather than a balance.
     */
    private function present(string $value, bool $roundUp): string
    {
        $scale     = Money::decimals();
        $truncated = bcadd($value, '0', $scale);

        if ($roundUp && bccomp($truncated, $value, 10) !== 0) {
            $truncated = bcadd($truncated, bcdiv('1', bcpow('10', (string) $scale), $scale), $scale);
        }

        return str_contains($truncated, '.')
            ? (rtrim(rtrim($truncated, '0'), '.') ?: '0')
            : $truncated;
    }

    /**
     * Whether the processor is the party actually constraining this method.
     * Worth surfacing, because it explains a range the operator did not set.
     */
    public function isLimitedByProvider(): bool
    {
        $platformMax = $this->{$this->platformMaxColumn()} ?? null;
        $effective   = $this->effectiveMaxAmount();

        if ($platformMax === null || $effective === null) {
            return $this->provider_max_amount !== null || $this->provider_min_amount !== null;
        }

        return bccomp($effective, DecimalMoney::normalize($platformMax), 8) < 0
            || bccomp($this->effectiveMinAmount(), DecimalMoney::normalize($this->{$this->platformMinColumn()} ?? 0), 8) > 0;
    }

    /**
     * Translate a processor limit into the base-currency amount a customer
     * would have to enter to reach it.
     *
     * The processor sees the converted total including the fee, so this undoes
     * both. With the fee stated in the processor's currency as
     * `fixed + percent` of the converted amount, the payable total is
     * `A x rate x (1 + pct/100) + fixed`, and solving for A gives the bound
     * below. A fee `min`/`max` clamp makes this an approximation, which is why
     * the result is rounded away from the boundary rather than towards it.
     */
    private function providerBound(mixed $limit, bool $roundUp): ?string
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        $rate = DecimalMoney::rate($this->conversion_rate ?? 1);

        if (bccomp($rate, '0', DecimalMoney::RATE_SCALE) <= 0) {
            return null;
        }

        $fixed   = DecimalMoney::normalize($this->charge_fixed ?? 0);
        $percent = DecimalMoney::normalize($this->charge_percent ?? 0);

        $net = bcsub(DecimalMoney::normalize($limit), $fixed, 10);

        if (bccomp($net, '0', 10) <= 0) {
            // The fixed fee alone already exceeds the processor's limit, so no
            // amount can satisfy it. Report zero rather than a negative bound.
            return '0.00000000';
        }

        $multiplier = bcadd('1', bcdiv($percent, '100', 10), 10);
        $divisor    = bcmul($rate, $multiplier, 18);

        if (bccomp($divisor, '0', 18) <= 0) {
            return null;
        }

        $bound = bcdiv($net, $divisor, 10);

        // Quantize to the precision the customer actually types in, always
        // away from the boundary: a ceiling rounds down and a floor rounds up,
        // so the figure on screen is one the server will accept. Showing
        // 107.61945759 would be exact and useless.
        $scale     = Money::decimals();
        $truncated = bcadd($bound, '0', $scale);

        if (! $roundUp || bccomp($truncated, $bound, 10) === 0) {
            return $truncated;
        }

        return bcadd($truncated, bcdiv('1', bcpow('10', (string) $scale), $scale), $scale);
    }

    abstract protected function platformMinColumn(): string;

    abstract protected function platformMaxColumn(): string;
}
