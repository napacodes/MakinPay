<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long an install ran, and who started it.
 *
 * The updater page lists every attempt, but until now a row could only say
 * what happened and when — never how long the site was down for it, nor
 * which administrator pressed the button. Both are nullable so rows written
 * by earlier releases keep rendering; the page prints an em dash for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_updates', function (Blueprint $table) {
            if (! Schema::hasColumn('project_updates', 'duration_ms')) {
                $table->unsignedBigInteger('duration_ms')->nullable()->after('installed_at');
            }

            if (! Schema::hasColumn('project_updates', 'installed_by')) {
                $table->string('installed_by')->nullable()->after('duration_ms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_updates', function (Blueprint $table) {
            foreach (['duration_ms', 'installed_by'] as $column) {
                if (Schema::hasColumn('project_updates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
