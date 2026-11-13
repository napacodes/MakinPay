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
         * Replay protection for money the app moves.
         *
         * A phone on a train sends a cash-in, loses the reply, and sends it
         * again. Without a record of the first attempt the second one is
         * indistinguishable from a second cash-in, and the customer is
         * credited twice. The merchant server-to-server API has carried this
         * since it shipped, against merchant_api_idempotencies; this is the
         * same guarantee for a Sanctum token, which has a user rather than a
         * merchant and no sandbox to separate.
         *
         * The stored response is what makes a retry safe rather than merely
         * refused: the second request is answered with exactly what the first
         * one produced, so the app cannot tell a replay from the original and
         * does not need to.
         *
         * request_hash is the guard against reuse. The same key with a
         * different body is not a retry - it is a client bug or an attack -
         * and is refused rather than replayed, because answering it with the
         * first request's result would silently discard a transfer somebody
         * meant to make.
         *
         * Unique on (user_id, idempotency_key): one customer's key cannot
         * collide with another's, and the database is what enforces it, so two
         * requests racing each other cannot both pass a read-then-write check.
         */
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_content_type', 128)->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'api_idempotency_owner_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
