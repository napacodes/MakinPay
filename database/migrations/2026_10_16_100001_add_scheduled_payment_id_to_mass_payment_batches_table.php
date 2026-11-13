<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch a schedule made: each run of a recurring mass payment is its own
 * batch, and this says which schedule rang it up, so the schedule's history
 * and the batch page can point at each other. Null for a batch the customer
 * built by hand.
 *
 * Guarded, so it is safe on an installation that already has the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mass_payment_batches') || Schema::hasColumn('mass_payment_batches', 'scheduled_payment_id')) {
            return;
        }

        Schema::table('mass_payment_batches', function (Blueprint $table): void {
            $table->foreignId('scheduled_payment_id')->nullable()->after('channel')->constrained('scheduled_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mass_payment_batches') || ! Schema::hasColumn('mass_payment_batches', 'scheduled_payment_id')) {
            return;
        }

        Schema::table('mass_payment_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('scheduled_payment_id');
        });
    }
};
