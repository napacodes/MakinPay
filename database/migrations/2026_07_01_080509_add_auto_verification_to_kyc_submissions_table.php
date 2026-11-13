<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->string('verification_method')->default('manual')->after('status');
            $table->string('provider')->nullable()->after('verification_method'); // kyc_providers.code
            $table->string('provider_reference')->nullable()->after('provider');  // idempotency key for webhooks
            $table->unsignedTinyInteger('risk_score')->nullable()->after('provider_reference');
            $table->json('auto_result')->nullable()->after('risk_score');
            $table->timestamp('verified_at')->nullable()->after('auto_result');

            $table->index('provider_reference');
            $table->index(['status', 'verification_method']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'verification_method']);
            $table->dropIndex(['provider_reference']);
            $table->dropColumn([
                'verification_method',
                'provider',
                'provider_reference',
                'risk_score',
                'auto_result',
                'verified_at',
            ]);
        });
    }
};
