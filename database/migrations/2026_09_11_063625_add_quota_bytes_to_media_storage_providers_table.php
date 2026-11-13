<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The storage rail reports "2.4 / 10 GB". The used half is summed from the
     * assets; the allowance has nowhere to live, so a provider that is not
     * given one simply reports usage without a ceiling.
     */
    public function up(): void
    {
        if (Schema::hasColumn('media_storage_providers', 'quota_bytes')) {
            return;
        }

        Schema::table('media_storage_providers', function (Blueprint $table): void {
            $table->unsignedBigInteger('quota_bytes')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('media_storage_providers', 'quota_bytes')) {
            return;
        }

        Schema::table('media_storage_providers', function (Blueprint $table): void {
            $table->dropColumn('quota_bytes');
        });
    }
};
