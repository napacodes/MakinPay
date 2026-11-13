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
            $table->index(['payout_gateway', 'created_at'], 'remittance_gateway_created_idx');
            $table->index(['status', 'created_at'], 'remittance_status_created_idx');
            $table->index(['dispatch_state', 'last_dispatch_at'], 'remittance_dispatch_activity_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('remittance_transfers', function (Blueprint $table) {
            $table->dropIndex('remittance_gateway_created_idx');
            $table->dropIndex('remittance_status_created_idx');
            $table->dropIndex('remittance_dispatch_activity_idx');
        });
    }
};
