<?php

declare(strict_types=1);

namespace App\Traits\Models;

use App\Support\FeeCalculator;

/**
 * Keep `profit_rate` / `profit_type` and `profit_fixed` / `profit_percent` in
 * step, in whichever direction was written.
 *
 * Wallet Earn plans and the stakes opened against them both carry the pair, and
 * both are written from more than one place: the admin form and the request
 * validator write the two components, while customer code, seeders and older
 * API consumers still write the legacy rate plus a discriminator. Syncing here
 * rather than at each call site is what stops the two representations drifting
 * — which is exactly how the deposit and withdraw fee columns went wrong
 * before they were consolidated.
 *
 * The legacy columns are the derived ones now: a configuration using both
 * components cannot be expressed by a single rate, so the percentage wins and
 * `profit_rate` records it. Nothing reads the legacy pair to compute money any
 * more, so that lossiness never reaches a payout.
 */
trait SyncsProfitComponents
{
    protected static function bootSyncsProfitComponents(): void
    {
        static::saving(function (self $model): void {
            // Both components are NOT NULL DEFAULT 0, but a blank form field
            // arrives as null: ConvertEmptyStringsToNull rewrites '' before
            // validation and `nullable` lets it through. An unconfigured
            // component means zero. `profit_min` / `profit_max` stay nullable —
            // "no floor" is not a floor of zero.
            foreach (['profit_fixed', 'profit_percent'] as $component) {
                if (array_key_exists($component, $model->getAttributes()) && $model->{$component} === null) {
                    $model->{$component} = 0;
                }
            }

            // Which representation wins is decided by content, not by
            // dirtiness: on an insert every attribute is dirty, so a
            // dirty-first rule made the legacy pair unreachable and a create()
            // carrying only `profit_rate` silently produced a plan that paid
            // nothing.
            if ((float) $model->profit_fixed > 0 || (float) $model->profit_percent > 0) {
                $usesPercent = (float) $model->profit_percent > 0;

                $model->profit_type = $usesPercent ? 'percentage' : 'fixed';
                $model->profit_rate = $usesPercent
                    ? (float) $model->profit_percent
                    : (float) $model->profit_fixed;

                return;
            }

            // Neither component is set, so a legacy write is the only thing
            // that can be carrying the terms. Gated on dirtiness so an
            // unrelated save cannot resurrect a rate the operator has since
            // replaced with components.
            if ($model->isDirty(['profit_rate', 'profit_type'])) {
                $type = $model->profit_type;

                $split = FeeCalculator::fromLegacy(
                    ($type instanceof \BackedEnum ? $type->value : $type) === 'percentage' ? 'percent' : 'fixed',
                    $model->profit_rate,
                );

                $model->profit_fixed   = $split['fixed'];
                $model->profit_percent = $split['percent'];
            }
        });
    }
}
