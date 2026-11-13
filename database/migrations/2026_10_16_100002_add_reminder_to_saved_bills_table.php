<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BP3: a monthly reminder on one of "My bills". `remind_day` is the day of
 * the month the customer asked to be reminded on (1-31; a day the month
 * does not have falls on its last day), null for no reminder. `reminded_for`
 * is the day the last reminder went out, so a month is reminded once.
 *
 * Guarded, so it is safe on an installation that already has the columns.
 */
return new class extends Migration
{
    private const TABLE = 'saved_bills';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'remind_day')) {
                $table->unsignedTinyInteger('remind_day')->nullable()->after('nickname');
            }

            if (! Schema::hasColumn(self::TABLE, 'reminded_for')) {
                $table->date('reminded_for')->nullable()->after('remind_day');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (['reminded_for', 'remind_day'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
