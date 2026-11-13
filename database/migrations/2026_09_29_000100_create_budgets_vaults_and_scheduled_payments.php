<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 7 of the app redesign: budgets per spending category, vaults as a
 * ring-fenced sub-balance of a wallet with their ledger, and scheduled
 * payments with the runs the scheduler made. Each table is guarded so an
 * upgrade replays safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('budgets')) {
            Schema::create('budgets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('category', 32);
                $table->decimal('amount', 24, 8);
                $table->string('currency', 10);
                $table->string('period', 12)->default('monthly');
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->unsignedTinyInteger('alert_threshold')->default(80);
                $table->boolean('rollover')->default(false);
                $table->string('last_alert_period', 16)->nullable();
                $table->unsignedTinyInteger('last_alert_level')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'category']);
            });
        }

        if (! Schema::hasTable('vaults')) {
            Schema::create('vaults', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('icon', 40)->default('piggy-bank');
                $table->decimal('target_amount', 24, 8)->nullable();
                $table->date('target_date')->nullable();
                $table->decimal('balance', 24, 8)->default(0);
                $table->string('status', 12)->default('active');
                $table->decimal('auto_amount', 24, 8)->nullable();
                $table->string('auto_frequency', 12)->nullable();
                $table->unsignedTinyInteger('auto_day')->nullable();
                $table->boolean('round_ups')->default(false);
                $table->decimal('round_up_to', 24, 8)->nullable();
                $table->timestamp('next_auto_at')->nullable();
                $table->timestamp('last_round_up_at')->nullable();
                $table->timestamp('paused_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['status', 'next_auto_at']);
            });
        }

        if (! Schema::hasTable('vault_entries')) {
            Schema::create('vault_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('vault_id')->constrained('vaults')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('kind', 16);
                $table->decimal('amount', 24, 8);
                $table->string('currency', 10);
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->index(['vault_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('scheduled_payments')) {
            Schema::create('scheduled_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 12);
                $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
                $table->decimal('amount', 24, 8);
                $table->string('currency', 10);
                $table->string('recipient', 255)->nullable();
                $table->string('recipient_name', 120)->nullable();
                $table->foreignId('vault_id')->nullable()->constrained('vaults')->nullOnDelete();
                $table->json('payload')->nullable();
                $table->string('cadence', 12);
                $table->unsignedSmallInteger('interval_days')->nullable();
                $table->json('weekdays')->nullable();
                $table->unsignedTinyInteger('day_of_month')->nullable();
                $table->date('starts_on');
                $table->date('ends_on')->nullable();
                $table->string('weekend_rule', 8)->default('none');
                $table->string('low_balance', 8)->default('retry');
                $table->unsignedTinyInteger('retry_days')->default(3);
                $table->foreignId('top_up_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
                $table->boolean('remind')->default(false);
                $table->string('remind_at', 5)->default('09:00');
                $table->string('note', 255)->nullable();
                $table->string('status', 12)->default('active');
                $table->timestamp('next_run_at')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->string('last_status', 12)->nullable();
                $table->string('failed_reason', 255)->nullable();
                $table->unsignedTinyInteger('retry_count')->default(0);
                $table->timestamp('reminded_for')->nullable();
                $table->uuid('mandate_id');
                $table->timestamp('paused_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['status', 'next_run_at']);
            });
        }

        if (! Schema::hasTable('scheduled_payment_runs')) {
            Schema::create('scheduled_payment_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scheduled_payment_id')->constrained('scheduled_payments')->cascadeOnDelete();
                $table->timestamp('due_at');
                $table->unsignedTinyInteger('attempt')->default(1);
                $table->timestamp('executed_at')->nullable();
                $table->string('status', 12);
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->string('reference', 64)->nullable();
                $table->decimal('amount', 24, 8);
                $table->string('currency', 10);
                $table->string('reason', 255)->nullable();
                $table->timestamps();

                $table->unique(['scheduled_payment_id', 'due_at', 'attempt'], 'scheduled_runs_once');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_payment_runs');
        Schema::dropIfExists('scheduled_payments');
        Schema::dropIfExists('vault_entries');
        Schema::dropIfExists('vaults');
        Schema::dropIfExists('budgets');
    }
};
