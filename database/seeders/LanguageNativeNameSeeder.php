<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Language;
use App\Support\LanguageNames;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Fills in each language's own name for rows that predate the column.
 *
 * A fresh install gets one as each language is added. An installation that
 * upgrades into the column has every row blank, and a blank row falls back to
 * the English name — correct, but not the point of the column.
 *
 * Additive by construction: a row whose native name is already set is left
 * exactly as the administrator wrote it, which is what makes this safe to
 * replay from reconcile_seeders on every update.
 */
class LanguageNativeNameSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('languages') || ! Schema::hasColumn('languages', 'native_name')) {
            return;
        }

        Language::query()
            ->where(function ($query): void {
                $query->whereNull('native_name')->orWhere('native_name', '');
            })
            ->get()
            ->each(function (Language $language): void {
                $native = LanguageNames::for((string) $language->code);

                /*
                 * No guess is better than a wrong one: with nothing to offer,
                 * the row goes on falling back to its English name and an
                 * administrator can fill the field in themselves.
                 */
                if ($native === null) {
                    return;
                }

                $language->forceFill(['native_name' => $native])->save();
            });
    }
}
