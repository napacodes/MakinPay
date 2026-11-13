<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the last percent-only fees a fixed component, plus a floor and ceiling.
 *
 * Merchant payment fees, P2P maker/taker fees and referral rewards could only
 * ever be a percentage, so "2.9% + 0.30" — how Stripe, PayPal and Wise
 * actually price — was unexpressible. These are the modules the core fee work
 * did not reach.
 *
 * The existing percentage columns are left in place and keep their meaning, so
 * every current configuration behaves exactly as before: a fixed component of
 * 0 makes the new formula collapse to the old one.
 *
 * `merchants.fee` is also lifted off `double`. A percentage stored as a float
 * is survivable, but there is no reason to keep it when every other fee column
 * is decimal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchants')) {
            Schema::table('merchants', function (Blueprint $table): void {
                if (! Schema::hasColumn('merchants', 'fee_fixed')) {
                    $table->decimal('fee_fixed', 24, 8)->default(0)->after('fee')
                        ->comment('Flat component added to the percentage fee.');
                }
                if (! Schema::hasColumn('merchants', 'fee_min')) {
                    $table->decimal('fee_min', 24, 8)->nullable()->after('fee_fixed');
                }
                if (! Schema::hasColumn('merchants', 'fee_max')) {
                    $table->decimal('fee_max', 24, 8)->nullable()->after('fee_min');
                }
            });

            // A percentage does not belong in a float column.
            Schema::table('merchants', function (Blueprint $table): void {
                $table->decimal('fee', 8, 4)->default(0)->change();
            });
        }

        // Payment links snapshot the merchant's pricing at creation time, so
        // the whole shape has to travel with the link.
        if (Schema::hasTable('payment_links')) {
            Schema::table('payment_links', function (Blueprint $table): void {
                foreach (['merchant_fee_fixed', 'merchant_fee_min', 'merchant_fee_max'] as $column) {
                    if (! Schema::hasColumn('payment_links', $column)) {
                        $table->decimal($column, 24, 8)->nullable()->after('merchant_fee');
                    }
                }
            });
        }

        if (Schema::hasTable('p2p_settings')) {
            Schema::table('p2p_settings', function (Blueprint $table): void {
                foreach ([
                    'maker_fee_fixed', 'maker_fee_min', 'maker_fee_max',
                    'taker_fee_fixed', 'taker_fee_min', 'taker_fee_max',
                ] as $column) {
                    if (! Schema::hasColumn('p2p_settings', $column)) {
                        $definition = $table->decimal($column, 24, 8);
                        str_ends_with($column, '_fixed') ? $definition->default(0) : $definition->nullable();
                    }
                }
            });
        }

        if (Schema::hasTable('rewards')) {
            Schema::table('rewards', function (Blueprint $table): void {
                if (! Schema::hasColumn('rewards', 'fixed_amount')) {
                    $table->decimal('fixed_amount', 24, 8)->default(0)->after('percentage')
                        ->comment('Flat reward added to the percentage reward.');
                }
                if (! Schema::hasColumn('rewards', 'min_reward')) {
                    $table->decimal('min_reward', 24, 8)->nullable()->after('fixed_amount');
                }
                if (! Schema::hasColumn('rewards', 'max_reward')) {
                    $table->decimal('max_reward', 24, 8)->nullable()->after('min_reward');
                }
            });
        }
    }

    public function down(): void
    {
        $this->dropIfPresent('merchants', ['fee_fixed', 'fee_min', 'fee_max']);
        $this->dropIfPresent('payment_links', ['merchant_fee_fixed', 'merchant_fee_min', 'merchant_fee_max']);
        $this->dropIfPresent('p2p_settings', [
            'maker_fee_fixed', 'maker_fee_min', 'maker_fee_max',
            'taker_fee_fixed', 'taker_fee_min', 'taker_fee_max',
        ]);
        $this->dropIfPresent('rewards', ['fixed_amount', 'min_reward', 'max_reward']);

        if (Schema::hasTable('merchants')) {
            Schema::table('merchants', function (Blueprint $table): void {
                $table->double('fee')->default(0)->change();
            });
        }
    }

    /** @param list<string> $columns */
    private function dropIfPresent(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_values(array_filter($columns, fn (string $c): bool => Schema::hasColumn($table, $c)));

        if ($present !== []) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($present));
        }
    }
};
