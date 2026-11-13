<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a KYC template took every verification with it.
     *
     * `kyc_template_id` has carried `onDelete('cascade')` since the table was
     * created in February, and `KycTemplateController::destroy()` asked
     * nothing before calling `delete()`. Removing a form an admin believed was
     * unused therefore destroyed every submission made against it — approved
     * identities, their append-only review logs, the consent records and the
     * document digests — and reported success on the way out.
     *
     * A verification record has to outlive the form it was collected on, so
     * the template is what gives way. The controller refuses first, naming the
     * count and pointing at pause; this is the backstop for every other path
     * to the row, now and later.
     *
     * Constraint-only, so it is safe on a fresh install and on an upgrade, and
     * it moves no data. The drop-and-re-add shape is the one the hosted-session
     * migration already uses on this same key.
     */
    public function up(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->dropForeign(['kyc_template_id']);
        });

        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->foreign('kyc_template_id')
                ->references('id')
                ->on('kyc_templates')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->dropForeign(['kyc_template_id']);
        });

        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->foreign('kyc_template_id')
                ->references('id')
                ->on('kyc_templates')
                ->onDelete('cascade');
        });
    }
};
