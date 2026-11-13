<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A provider-hosted journey has no template behind it: the applicant
        // photographs their documents inside the provider's own flow, so there
        // is no local form and nothing to tie a kyc_templates row to.
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->dropForeign(['kyc_template_id']);
        });

        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('kyc_template_id')->nullable()->change();
        });

        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->foreign('kyc_template_id')
                ->references('id')
                ->on('kyc_templates')
                ->onDelete('cascade');

            // Where to send an applicant who abandoned the provider flow, and
            // when that link stops working. Kept so returning to the KYC page
            // can offer "continue" instead of stranding them on a pending row.
            $table->text('provider_session_url')->nullable()->after('provider_reference');
            $table->timestamp('provider_session_expires_at')->nullable()->after('provider_session_url');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->dropColumn(['provider_session_url', 'provider_session_expires_at']);
            $table->dropForeign(['kyc_template_id']);
        });

        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('kyc_template_id')->nullable(false)->change();
        });

        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->foreign('kyc_template_id')
                ->references('id')
                ->on('kyc_templates')
                ->onDelete('cascade');
        });
    }
};
