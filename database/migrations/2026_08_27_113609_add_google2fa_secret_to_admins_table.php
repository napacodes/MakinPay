<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `create_admins_table` never shipped this column even though `Admin` has
     * always declared it fillable, so admin two-factor setup wrote to a column
     * that did not exist. Guarded so installations whose distributed database
     * dump already carries the column upgrade cleanly.
     */
    public function up(): void
    {
        if (Schema::hasColumn('admins', 'google2fa_secret')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table) {
            $table->string('google2fa_secret')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('admins', 'google2fa_secret')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('google2fa_secret');
        });
    }
};
