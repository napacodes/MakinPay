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
        Schema::create('kyc_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();          // slug used in the webhook URL + config
            $table->string('name');                    // admin-facing label
            $table->string('driver');                  // smile_id|shufti|sumsub|manual
            $table->json('countries')->nullable();     // ISO-3166 alpha-2 list, or ["*"] for any
            $table->unsignedSmallInteger('priority')->default(0); // higher wins when multiple match
            $table->string('mode')->default('sandbox'); // sandbox|live
            $table->text('credentials')->nullable();   // encrypted JSON (API keys, secrets)
            $table->boolean('status')->default(false);
            $table->timestamps();

            $table->index(['status', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_providers');
    }
};
