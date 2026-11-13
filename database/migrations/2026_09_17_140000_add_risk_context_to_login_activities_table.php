<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The context the Activity history screen reads: where the sign-in came from
 * (city, ISP), what it arrived on (device type), how it authenticated, what
 * the risk evaluator concluded, and which session it opened.
 *
 * Every column is nullable or defaulted. An upgraded installation keeps every
 * row it already has, and those rows read as `normal` risk with no geography
 * until the evaluator sees that account sign in again — which is honest,
 * because nothing was measured about them at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_activities', function (Blueprint $table): void {
            if (! Schema::hasColumn('login_activities', 'city')) {
                $table->string('city')->nullable()->after('country');
            }

            if (! Schema::hasColumn('login_activities', 'isp')) {
                $table->string('isp')->nullable()->after('city');
            }

            if (! Schema::hasColumn('login_activities', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('isp');
            }

            if (! Schema::hasColumn('login_activities', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }

            if (! Schema::hasColumn('login_activities', 'device_type')) {
                $table->string('device_type', 20)->nullable()->after('device');
            }

            if (! Schema::hasColumn('login_activities', 'method')) {
                $table->string('method', 20)->nullable()->after('device_type');
            }

            if (! Schema::hasColumn('login_activities', 'risk')) {
                $table->string('risk', 20)->default('normal')->after('method');
            }

            if (! Schema::hasColumn('login_activities', 'risk_reason')) {
                $table->string('risk_reason', 255)->nullable()->after('risk');
            }

            if (! Schema::hasColumn('login_activities', 'session_id')) {
                $table->string('session_id')->nullable()->after('risk_reason');
            }

            if (! Schema::hasColumn('login_activities', 'failed_attempts_before')) {
                $table->unsignedSmallInteger('failed_attempts_before')->default(0)->after('session_id');
            }

            if (! Schema::hasColumn('login_activities', 'session_revoked_at')) {
                $table->timestamp('session_revoked_at')->nullable()->after('failed_attempts_before');
            }
        });

        /*
         * The screen's default view is "flagged sign-ins in the last 7 days",
         * which is exactly these two columns; without the index that is a
         * table scan on the one table that grows with every sign-in.
         */
        Schema::table('login_activities', function (Blueprint $table): void {
            if (! $this->hasIndex('login_activities_risk_login_at_index')) {
                $table->index(['risk', 'login_at'], 'login_activities_risk_login_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('login_activities', function (Blueprint $table): void {
            if ($this->hasIndex('login_activities_risk_login_at_index')) {
                $table->dropIndex('login_activities_risk_login_at_index');
            }

            $columns = array_filter([
                'city', 'isp', 'latitude', 'longitude', 'device_type', 'method',
                'risk', 'risk_reason', 'session_id', 'failed_attempts_before', 'session_revoked_at',
            ], static fn (string $column): bool => Schema::hasColumn('login_activities', $column));

            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        try {
            return collect(Schema::getIndexes('login_activities'))
                ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
        } catch (Throwable) {
            return false;
        }
    }
};
