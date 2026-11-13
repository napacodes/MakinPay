<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The migration-built `page_components` schema drifted from the one the
 * shipped SQL dump creates: production carries `is_protected` (the
 * PageComponent model casts it, and the dump's `blog_standard` row relies on
 * it) and a nullable `sort`, while `create_page_components_table` shipped
 * neither. Any installation built by `migrate` alone therefore breaks the
 * moment a protected or unsorted component row is written.
 *
 * Guarded per column so installations whose dump already carries the correct
 * shape upgrade as a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('page_components')) {
            return;
        }

        if (! Schema::hasColumn('page_components', 'is_protected')) {
            Schema::table('page_components', function (Blueprint $table) {
                $table->boolean('is_protected')->default(false)->after('repeated_content');
            });
        }

        Schema::table('page_components', function (Blueprint $table) {
            $table->integer('sort')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('page_components')) {
            return;
        }

        if (Schema::hasColumn('page_components', 'is_protected')) {
            Schema::table('page_components', function (Blueprint $table) {
                $table->dropColumn('is_protected');
            });
        }
    }
};
