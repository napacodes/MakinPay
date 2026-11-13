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
            $table->index(['user_id', 'created_at'], 'remittance_user_created_idx');
            $table->index(['user_id', 'status', 'created_at'], 'remittance_user_status_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('remittance_transfers', function (Blueprint $table) {
            $table->dropIndex('remittance_user_created_idx');
            $table->dropIndex('remittance_user_status_created_idx');
        });
    }
};
