<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What the last catalogue sync of each country did, per bill payment
 * provider: {CC: {at, dry_run, report}}.
 *
 * Its own column rather than a key inside `config`, so the admin form saving
 * `config` can never race with a queued sync writing its results. Added,
 * never backfilled, and guarded so a second run is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bill_payment_providers') || Schema::hasColumn('bill_payment_providers', 'sync_state')) {
            return;
        }

        Schema::table('bill_payment_providers', function (Blueprint $table): void {
            $table->json('sync_state')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bill_payment_providers') || ! Schema::hasColumn('bill_payment_providers', 'sync_state')) {
            return;
        }

        Schema::table('bill_payment_providers', function (Blueprint $table): void {
            $table->dropColumn('sync_state');
        });
    }
};
