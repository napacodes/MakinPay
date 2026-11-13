<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_push_tokens')) {
            return;
        }

        /*
         * Where to wake a customer's phone.
         *
         * Kept apart from personal_access_tokens on purpose, even though that
         * table already knows about devices. A push token belongs to the app
         * INSTALL and an access token belongs to a SESSION: signing out and
         * back in rotates the access token while the install keeps the same
         * push registration, and a push token can be rotated by the operating
         * system without the session changing at all. Storing one on the other
         * would mean losing a registration on every sign-in.
         *
         * Unique on (user_id, device_id), so re-registering the same handset
         * updates rather than accumulating - a phone that reinstalls and
         * registers again must not leave a dead token behind that every later
         * notification still tries.
         *
         * The token itself is not a secret in the sense a password is - it
         * authorises nothing on its own, it only addresses a device - but it
         * is personal data that identifies an install, so it is deleted with
         * the user rather than orphaned.
         */
        Schema::create('user_push_tokens', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Chosen by the app and stable across reinstalls where the platform
            // allows it; nullable so a client that cannot produce one still
            // registers, keyed by the token alone.
            $table->string('device_id', 64)->nullable();

            // FCM registration tokens are long and have no documented ceiling.
            $table->text('token');

            $table->string('platform', 20)->nullable();
            $table->string('app_version', 20)->nullable();

            // When the app last told us this token is still the right one.
            $table->timestamp('last_registered_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'device_id'], 'user_push_tokens_owner_device_unique');
            $table->index('user_id', 'user_push_tokens_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_push_tokens');
    }
};
