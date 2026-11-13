<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deny list was hand-written only, so every row was permanent and
 * every row had an administrator behind it. `IpAbuseGuard` now writes to
 * the same table on its own, and an address it blocked has to be
 * distinguishable from one a human chose:
 *
 *   - `source` says who wrote the row, so the screen can label it and so
 *     the guard never escalates or overwrites a manual decision.
 *   - `expires_at` makes an automatic block self-healing. A manual block
 *     stays null (forever) exactly as before.
 *   - `strikes` is what tripped it, kept for the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_blocks', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('reason');
            $table->unsignedInteger('strikes')->default(0)->after('source');
            $table->timestamp('expires_at')->nullable()->after('blocked_at');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('ip_blocks', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['source', 'strikes', 'expires_at']);
        });
    }
};
