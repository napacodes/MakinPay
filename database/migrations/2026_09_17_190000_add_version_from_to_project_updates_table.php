<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which version an install started from.
 *
 * A rollback restores the files an install replaced, so it has to know the
 * version those files belonged to. The backup usually carries the old
 * config/project_updater.php and therefore the old version string, but
 * "usually" is not a thing to hang a restore on — this records it outright.
 *
 * Nullable, because every row written before this column existed has no
 * answer; rollback falls back to reading the version out of the backed-up
 * config for those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_updates', function (Blueprint $table) {
            if (! Schema::hasColumn('project_updates', 'version_from')) {
                $table->string('version_from')->nullable()->after('version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_updates', function (Blueprint $table) {
            if (Schema::hasColumn('project_updates', 'version_from')) {
                $table->dropColumn('version_from');
            }
        });
    }
};
