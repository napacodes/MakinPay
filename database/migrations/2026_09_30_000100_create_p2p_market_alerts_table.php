<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 8 of the app redesign: a saved search on the P2P market - "tell me
 * when a BTC ad in NGN appears". Guarded so an upgrade replays safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('p2p_market_alerts')) {
            return;
        }

        Schema::create('p2p_market_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('side', 4);
            $table->string('asset', 20);
            $table->string('fiat', 20);
            $table->foreignId('payment_method_id')->nullable()->constrained('p2p_payment_methods')->nullOnDelete();
            $table->decimal('amount', 24, 8)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p2p_market_alerts');
    }
};
