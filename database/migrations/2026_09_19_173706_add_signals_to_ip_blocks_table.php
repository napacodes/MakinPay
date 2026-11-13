<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the address actually did, kept with the block it earned.
 *
 * `strikes` says how far past the threshold it went; it does not say which
 * refusals got it there, and that is the first question an administrator
 * asks. The counter that produced them lives in the cache and lapses with
 * its window, so the breakdown has to be copied onto the row at the moment
 * the block is written, or it is gone by the time anyone looks.
 *
 * Shape: {"login_lockout": 3, "forbidden": 4} — signal key to how many times
 * it fired. Weights are configuration rather than data, so they are not
 * stored: the screen multiplies by the live weight when it renders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_blocks', function (Blueprint $table) {
            $table->json('signals')->nullable()->after('strikes');
        });
    }

    public function down(): void
    {
        Schema::table('ip_blocks', function (Blueprint $table) {
            $table->dropColumn('signals');
        });
    }
};
