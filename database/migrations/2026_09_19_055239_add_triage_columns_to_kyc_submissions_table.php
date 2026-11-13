<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Two triage facts about a submission that are not decisions about it:
         * who picked it up, and whether it is worth coming back to.
         *
         * Nothing writes these today. The queue selection and bulk actions that
         * would have set them were built and then removed, because a checkbox
         * column in every row made the table read as busier than the work it
         * describes. The columns are kept so that decision stays reversible in
         * the UI alone: bringing assignment back is a screen and an endpoint,
         * not another schema change on installations that have already run this.
         *
         * Both nullable, so their presence changes nothing until something does
         * start writing them.
         */
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('kyc_submissions', 'assigned_admin_id')) {
                $table->foreignId('assigned_admin_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('admins')
                    // The queue entry outlives the reviewer's account: losing
                    // an admin must never delete the submission they held.
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('kyc_submissions', 'watchlisted_at')) {
                $table->timestamp('watchlisted_at')->nullable()->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            if (Schema::hasColumn('kyc_submissions', 'assigned_admin_id')) {
                $table->dropConstrainedForeignId('assigned_admin_id');
            }

            if (Schema::hasColumn('kyc_submissions', 'watchlisted_at')) {
                $table->dropColumn('watchlisted_at');
            }
        });
    }
};
