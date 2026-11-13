<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's standing "tell me when 1 USD buys 0.93 EUR". Additive: a
 * new table nothing else reads until the app creates rows in it.
 *
 * `auto_exchange` rows also name the two wallets and the amount to move,
 * because the scheduler that fires them runs with no request and no PIN
 * - the PIN was checked when the alert was created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rate_alerts')) {
            return;
        }

        Schema::create('rate_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('from_currency', 10);
            $table->string('to_currency', 10);
            $table->decimal('target_rate', 36, 18);
            $table->string('direction', 8)->default('above');
            $table->boolean('auto_exchange')->default(false);
            $table->decimal('amount', 36, 18)->nullable();
            $table->foreignId('source_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->foreignId('destination_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->decimal('triggered_rate', 36, 18)->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'from_currency', 'to_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_alerts');
    }
};
