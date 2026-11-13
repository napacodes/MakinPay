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
         * Why a submission was turned down, as a code rather than as prose.
         *
         * The remarks column stays: it says what this applicant should do.
         * This says which kind of problem it was, which is the part a second
         * reviewer, a report or the rejection email can rely on — the email
         * has always interpolated a reason, and until now could be handed
         * nothing at all.
         *
         * Nullable and added, never backfilled: decisions taken before this
         * existed genuinely have no code, and inventing one for them would
         * put a fact in the audit trail that nobody ever recorded.
         */
        Schema::table('kyc_review_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('kyc_review_logs', 'reason_code')) {
                $table->string('reason_code', 32)->nullable()->after('action');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kyc_review_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('kyc_review_logs', 'reason_code')) {
                $table->dropColumn('reason_code');
            }
        });
    }
};
