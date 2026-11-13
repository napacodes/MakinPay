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
        Schema::create('kyc_review_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained('kyc_submissions')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('action');
            $table->unsignedTinyInteger('from_status')->nullable();
            $table->unsignedTinyInteger('to_status');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('kyc_submission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_review_logs');
    }
};
