<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * The tiers below a customer's rank, which UpdateUserRanking writes on every
     * promotion and the rank screens read to show what has been earned.
     *
     * No migration ever created this column. It came only from DB/digikash.sql,
     * the dump the MySQL installer imports - so an installation built by the
     * migrations alone (the SQLite path, or MySQL without the dump) lacked it,
     * and every promotion there was an SQL error: no new rank, no rank reward,
     * and an exception in whatever fired the event.
     *
     * Added only where it is missing, so a dump-based installation, which
     * already has it, is untouched.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'old_ranks')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->json('old_ranks')->nullable()->after('rank_id');
        });
    }

    /*
     * Nothing. On a dump-based installation the column predates this
     * migration, and dropping it would take every customer's earned tiers
     * with it; this migration cannot tell the two cases apart afterwards.
     */
    public function down(): void {}
};
