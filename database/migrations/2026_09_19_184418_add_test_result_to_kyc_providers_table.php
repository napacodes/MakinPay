<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The connection test already ran; nothing remembered that it had.
 *
 * KycProviderController::test() returned a JSON verdict to the browser and
 * threw it away, so the provider card could not say when the engine was last
 * reached or whether it answered. Both columns are nullable: a provider
 * nobody has tested yet simply has no reading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_providers', function (Blueprint $table): void {
            if (! Schema::hasColumn('kyc_providers', 'last_tested_at')) {
                $table->timestamp('last_tested_at')->nullable()->after('priority');
            }

            if (! Schema::hasColumn('kyc_providers', 'last_test_status')) {
                $table->string('last_test_status', 20)->nullable()->after('last_tested_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kyc_providers', function (Blueprint $table): void {
            $table->dropColumn(['last_test_status', 'last_tested_at']);
        });
    }
};
