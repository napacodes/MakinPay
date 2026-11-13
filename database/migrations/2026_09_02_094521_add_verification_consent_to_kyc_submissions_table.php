<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the applicant's consent to automated identity verification.
 *
 * Automated KYC sends a customer's name, document images and government ID
 * number to a third-party provider. That transfer needs the customer's explicit,
 * recorded agreement, and an agreement is only evidence if you can show what was
 * actually put in front of them — so the wording is snapshotted per submission
 * rather than referenced, and survives an administrator later editing the text.
 *
 * All columns are nullable: submissions predating this, and any made while
 * automation is switched off, simply carry no consent record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kyc_submissions')) {
            return;
        }

        Schema::table('kyc_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('kyc_submissions', 'consent_accepted_at')) {
                $table->timestamp('consent_accepted_at')->nullable()->after('verified_at');
            }

            if (! Schema::hasColumn('kyc_submissions', 'consent_ip')) {
                $table->string('consent_ip', 45)->nullable()->after('consent_accepted_at');
            }

            if (! Schema::hasColumn('kyc_submissions', 'consent_text')) {
                $table->text('consent_text')->nullable()->after('consent_ip');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kyc_submissions')) {
            return;
        }

        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->dropColumn(['consent_accepted_at', 'consent_ip', 'consent_text']);
        });
    }
};
