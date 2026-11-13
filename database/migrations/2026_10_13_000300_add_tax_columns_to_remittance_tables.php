<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The tax a remittance carries (GF6), locked with its quote so a rule an
 * administrator edits between the quote and the send cannot change what the
 * customer agreed to pay, and copied onto the transfer it becomes.
 *
 *   tax_amount     in the source currency, inside total_pay / total_paid:
 *                  total = send_amount + fee_amount + tax_amount.
 *   tax_breakdown  one entry per rule that applied, as on transactions.
 *
 * Nullable: a quote or a transfer from before GF6, or with no rule that
 * applies, has none. Guarded so a second run is harmless.
 */
return new class extends Migration
{
    /** @var array<string, string> table => the column the tax follows */
    private const TABLES = [
        'remittance_quotes'    => 'fee_amount',
        'remittance_transfers' => 'fee_amount',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $after) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $after): void {
                if (! Schema::hasColumn($table, 'tax_amount')) {
                    $blueprint->decimal('tax_amount', 24, 8)->nullable()->after($after);
                }

                if (! Schema::hasColumn($table, 'tax_breakdown')) {
                    $blueprint->json('tax_breakdown')->nullable()->after('tax_amount');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                foreach (['tax_breakdown', 'tax_amount'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
