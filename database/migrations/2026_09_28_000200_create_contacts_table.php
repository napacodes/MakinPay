<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's address book on the platform: the people they favourite,
 * nickname or block, and the phone-book matches they synced. The list the
 * app shows is this table merged with the counterparties already in the
 * ledger, so a row exists only once the customer touches a contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts')) {
            return;
        }

        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('contact_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('nickname', 60)->nullable();
            $table->string('display_name', 120)->nullable();
            $table->string('phone_hash', 64)->nullable();
            $table->string('source', 16)->default('manual');
            $table->boolean('favorite')->default(false);
            $table->boolean('blocked')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'contact_user_id']);
            $table->index(['user_id', 'phone_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
