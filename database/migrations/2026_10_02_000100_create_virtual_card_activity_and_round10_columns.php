<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 10 of the app: a card's own ledger (purchases, refunds, top-ups,
 * withdrawals and declines with why), when a freeze lifts on its own, and
 * a replacement or renewal request pointing back at the card it replaces.
 * Every step is guarded, so an installation that already has any of it is
 * left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('virtual_card_activity')) {
            Schema::create('virtual_card_activity', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('virtual_card_id')->constrained('virtual_cards')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('kind', 16);
                $table->string('status', 16)->default('settled');
                $table->string('merchant')->nullable();
                $table->string('category', 64)->nullable();
                $table->string('channel', 16)->nullable();
                $table->decimal('amount', 24, 8);
                $table->string('currency', 10);
                $table->decimal('original_amount', 24, 8)->nullable();
                $table->string('original_currency', 10)->nullable();
                $table->string('decline_reason', 32)->nullable();
                $table->string('provider_reference')->nullable();
                $table->timestamp('occurred_at');
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['virtual_card_id', 'provider_reference']);
                $table->index(['virtual_card_id', 'occurred_at']);
                $table->index(['user_id', 'occurred_at']);
            });
        }

        Schema::table('virtual_cards', function (Blueprint $table): void {
            if (! Schema::hasColumn('virtual_cards', 'frozen_until')) {
                $table->timestamp('frozen_until')->nullable()->after('status');
            }
            if (! Schema::hasColumn('virtual_cards', 'replaced_by_request_id')) {
                $table->unsignedBigInteger('replaced_by_request_id')->nullable()->after('frozen_until');
            }
            if (! Schema::hasColumn('virtual_cards', 'activity_synced_at')) {
                $table->timestamp('activity_synced_at')->nullable()->after('replaced_by_request_id');
            }
        });

        Schema::table('virtual_card_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('virtual_card_requests', 'kind')) {
                $table->string('kind', 16)->default('new')->after('status');
            }
            if (! Schema::hasColumn('virtual_card_requests', 'replaces_card_id')) {
                $table->unsignedBigInteger('replaces_card_id')->nullable()->after('kind');
            }
        });
    }

    public function down(): void
    {
        Schema::table('virtual_card_requests', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'replaces_card_id']);
        });
        Schema::table('virtual_cards', function (Blueprint $table): void {
            $table->dropColumn(['frozen_until', 'replaced_by_request_id', 'activity_synced_at']);
        });
        Schema::dropIfExists('virtual_card_activity');
    }
};
