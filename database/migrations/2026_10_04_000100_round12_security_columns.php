<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 12 of the app: when the password was last changed, so the
 * security centre can say how old it is, and whether a customer has
 * looked at a sign-in the risk rules flagged, so Home stops asking.
 * Both guarded; an installation that already has either is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('password');
            }
        });

        Schema::table('login_activities', function (Blueprint $table): void {
            if (! Schema::hasColumn('login_activities', 'acknowledged_at')) {
                $table->timestamp('acknowledged_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('login_activities', function (Blueprint $table): void {
            if (Schema::hasColumn('login_activities', 'acknowledged_at')) {
                $table->dropColumn('acknowledged_at');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'password_changed_at')) {
                $table->dropColumn('password_changed_at');
            }
        });
    }
};
