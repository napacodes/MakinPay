<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The provider's own id for a notice it sent about a payout line, so a
 * replayed webhook is recorded once.
 *
 * This supersedes, for external notices only, the "no unique key" note in
 * 2026_10_11_000300_create_mass_payment_events_table: that held while nothing
 * external was replayed into the trail, and payout webhooks now are. The key
 * is (row_id, external_event_id). Every GF4 event leaves external_event_id
 * null, and nulls never collide, so the append-only trail is unaffected. The
 * model still refuses updates and deletes.
 *
 * Guarded so a partial earlier run, or a second run, is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'mass_payment_events';

    private const UNIQUE = 'mass_payment_events_row_external_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'external_event_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('external_event_id', 191)->nullable()->after('row_id');
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['row_id', 'external_event_id'], self::UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, self::UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'external_event_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('external_event_id');
            });
        }
    }
};
