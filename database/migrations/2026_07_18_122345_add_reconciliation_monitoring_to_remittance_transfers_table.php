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
        Schema::table('remittance_transfers', function (Blueprint $table) {
            $table->timestamp('last_reconciled_at')->nullable()->after('last_dispatch_at');
            $table->unsignedSmallInteger('reconciliation_attempts')->default(0)->after('last_reconciled_at');
            $table->string('reconciliation_error', 500)->nullable()->after('reconciliation_attempts');
            $table->timestamp('reconciliation_alerted_at')->nullable()->after('reconciliation_error');

            $table->index(['status', 'last_reconciled_at'], 'remittance_status_reconciled_idx');
            $table->index(['dispatch_state', 'reconciliation_alerted_at'], 'remittance_dispatch_alerted_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('remittance_transfers', function (Blueprint $table) {
            $table->dropIndex('remittance_status_reconciled_idx');
            $table->dropIndex('remittance_dispatch_alerted_idx');
            $table->dropColumn([
                'last_reconciled_at',
                'reconciliation_attempts',
                'reconciliation_error',
                'reconciliation_alerted_at',
            ]);
        });
    }
};
