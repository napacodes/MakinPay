<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 9 of the app: a floating ad price, review tags, account
 * verification, and blocking a trader. Every step is guarded, so an
 * installation that already has any of it is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p2p_offers', function (Blueprint $table): void {
            if (! Schema::hasColumn('p2p_offers', 'price_type')) {
                $table->string('price_type', 10)->default('FIXED')->after('price');
            }
            if (! Schema::hasColumn('p2p_offers', 'margin_pct')) {
                $table->decimal('margin_pct', 8, 4)->nullable()->after('price_type');
            }
        });

        Schema::table('p2p_offer_feedback', function (Blueprint $table): void {
            if (! Schema::hasColumn('p2p_offer_feedback', 'tags')) {
                $table->json('tags')->nullable()->after('comment');
            }
        });

        Schema::table('p2p_payment_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('p2p_payment_accounts', 'verification_status')) {
                $table->string('verification_status', 16)->default('NONE')->after('display_value');
            }
            if (! Schema::hasColumn('p2p_payment_accounts', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verification_status');
            }
        });

        if (! Schema::hasTable('p2p_blocks')) {
            Schema::create('p2p_blocks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'blocked_user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('p2p_blocks');

        Schema::table('p2p_payment_accounts', function (Blueprint $table): void {
            $table->dropColumn(['verification_status', 'verified_at']);
        });
        Schema::table('p2p_offer_feedback', function (Blueprint $table): void {
            $table->dropColumn('tags');
        });
        Schema::table('p2p_offers', function (Blueprint $table): void {
            $table->dropColumn(['price_type', 'margin_pct']);
        });
    }
};
