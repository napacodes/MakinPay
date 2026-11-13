<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 6 of the app redesign: the phone numbers a customer keeps for
 * recharges, the spending categories they taught the classifier per
 * merchant, and how they want to be notified. Each table is guarded so an
 * upgrade replays safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saved_phone_numbers')) {
            Schema::create('saved_phone_numbers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('phone_number', 32);
                $table->string('label', 60)->nullable();
                $table->string('operator', 64)->nullable();
                $table->string('country', 2)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'phone_number']);
            });
        }

        if (! Schema::hasTable('merchant_categories')) {
            Schema::create('merchant_categories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('merchant_key', 160);
                $table->string('category', 32);
                $table->timestamps();

                $table->unique(['user_id', 'merchant_key']);
            });
        }

        if (! Schema::hasTable('notification_settings')) {
            Schema::create('notification_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->json('channels')->nullable();
                $table->string('quiet_from', 5)->nullable();
                $table->string('quiet_to', 5)->nullable();
                $table->decimal('large_transaction_threshold', 24, 8)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('merchant_categories');
        Schema::dropIfExists('saved_phone_numbers');
    }
};
