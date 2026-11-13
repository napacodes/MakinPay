<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each integration last answered a connection test.
 *
 * The Integration Center states this on every card: "Tested 2h ago" against
 * "Not tested" is what separates a provider somebody has actually checked from
 * one that merely has keys typed into it. Nullable, because every plugin that
 * already exists has never been tested by definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('plugins', 'last_tested_at')) {
            return;
        }

        Schema::table('plugins', function (Blueprint $table): void {
            $table->timestamp('last_tested_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('plugins', 'last_tested_at')) {
            return;
        }

        Schema::table('plugins', function (Blueprint $table): void {
            $table->dropColumn('last_tested_at');
        });
    }
};
