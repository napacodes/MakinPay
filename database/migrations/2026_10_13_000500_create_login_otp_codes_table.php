<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One-time sign-in codes sent by SMS (GF9). Kept apart from
 * phone_verification_codes: a sign-in code must never be accepted as proof
 * of a phone, nor a verification code as a sign-in.
 *
 * Only a hash of the code is stored. `consumed_at` makes a code single-use;
 * `attempts` counts wrong entries against it. Guarded so a second run is
 * harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('login_otp_codes')) {
            return;
        }

        Schema::create('login_otp_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number', 32);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'login_otp_codes_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otp_codes');
    }
};
