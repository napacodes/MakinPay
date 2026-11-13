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
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('balance', 20, 8)->default(0)->change();
        });

        Schema::table('remittance_quotes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('remittance_transfers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['transaction_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('transaction_id')->references('id')->on('transactions')->restrictOnDelete();
            $table->string('client_idempotency_key', 64)->nullable()->after('provider_idempotency_key');
            $table->char('client_request_hash', 64)->nullable()->after('client_idempotency_key');
            $table->unique(['user_id', 'client_idempotency_key'], 'remittance_user_idempotency_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('remittance_transfers', function (Blueprint $table) {
            $table->dropUnique('remittance_user_idempotency_unique');
            $table->dropForeign(['transaction_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['client_idempotency_key', 'client_request_hash']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('transaction_id')->references('id')->on('transactions')->nullOnDelete();
        });

        Schema::table('remittance_quotes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->float('balance')->default(0)->change();
        });
    }
};
