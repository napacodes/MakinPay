<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An optional name the customer gives a batch ("Salaries October"), shown in
 * the history on the web and in the app beside the MP reference.
 *
 * Nullable and never backfilled: a batch built before this keeps no name and
 * is listed by its reference, exactly as before. Guarded, so a second run or
 * a partial earlier one is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'mass_payment_batches';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'name')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('name', 100)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'name')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }
};
