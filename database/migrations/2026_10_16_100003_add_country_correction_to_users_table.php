<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PH7: the audit note of an admin correcting an account's registered
 * country. Tax rules read local or international from `users.country`, so
 * a correction says what it was, who changed it and when; the columns stay
 * null for an account whose country was never corrected.
 *
 * Guarded, so it is safe on an installation that already has the columns.
 */
return new class extends Migration
{
    private const TABLE = 'users';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'country_corrected_from')) {
                $table->string('country_corrected_from', 2)->nullable()->after('country');
            }

            if (! Schema::hasColumn(self::TABLE, 'country_corrected_at')) {
                $table->timestamp('country_corrected_at')->nullable()->after('country_corrected_from');
            }

            if (! Schema::hasColumn(self::TABLE, 'country_corrected_by')) {
                $table->foreignId('country_corrected_by')->nullable()->after('country_corrected_at')->constrained('admins')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasColumn(self::TABLE, 'country_corrected_by')) {
                $table->dropConstrainedForeignId('country_corrected_by');
            }

            foreach (['country_corrected_at', 'country_corrected_from'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
