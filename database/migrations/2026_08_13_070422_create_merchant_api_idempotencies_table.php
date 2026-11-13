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
        Schema::create('merchant_api_idempotencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('idempotency_key', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_content_type', 100)->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(
                ['merchant_id', 'environment', 'idempotency_key'],
                'merchant_api_idempotency_owner_key_unique'
            );
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_api_idempotencies');
    }
};
