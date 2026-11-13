<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `create_admins_table` never shipped this column either, although the
     * `Admin` model declares it fillable and the two-factor middleware reads
     * it on every admin request. On an installation that came up from the
     * migrations rather than the distributed dump, enabling admin two-factor
     * failed on write and the challenge was never enforced. Guarded so
     * installations whose dump already carries the column upgrade cleanly.
     */
    public function up(): void
    {
        if (Schema::hasColumn('admins', 'two_factor_enabled')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('two_factor_enabled')->default(false)->after('google2fa_secret');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('admins', 'two_factor_enabled')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('two_factor_enabled');
        });
    }
};
