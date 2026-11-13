<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for automated exchange-rate rebasing.
 *
 * Changing the site base currency rewrites every stored rate. One row is
 * written per currency under a shared batch_uuid, which gives an exact undo and
 * an auditable history — non-negotiable when the write is to financial config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rate_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_uuid')->index();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->string('currency_code', 10);
            $table->decimal('old_rate', 36, 18);
            $table->decimal('new_rate', 36, 18);
            $table->string('old_base_code', 10);
            $table->string('new_base_code', 10);
            $table->string('reason', 64)->default('base_currency_change');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['currency_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rate_revisions');
    }
};
