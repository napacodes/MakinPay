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
         * Why an agent request was turned down.
         *
         * The rejection notification has always interpolated {rejection_reason}
         * and AgentActionRequest has always validated the field — but the review
         * modal never rendered one, so every rejection e-mail ever sent read
         * "No reason provided", and the admin's actual reason, where one existed
         * at all, was discarded the moment the request ended.
         *
         * Storing it does two things the notification alone cannot: the rejected
         * profile can show what was sent, and a second administrator looking at
         * the same row can see why the first one said no.
         *
         * 500 matches the cap the form request already enforces. Nullable and
         * added, never backfilled: rejections taken before this existed
         * genuinely have no reason on record, and inventing one would put a fact
         * on the row nobody ever wrote.
         *
         * Shaped to match add_rejection_reason_to_merchants_table, because the
         * same people review both queues and the two rows should not disagree
         * about what a rejection looks like.
         */
        Schema::table('agents', function (Blueprint $table): void {
            if (! Schema::hasColumn('agents', 'rejection_reason')) {
                $table->string('rejection_reason', 500)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            if (Schema::hasColumn('agents', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
        });
    }
};
