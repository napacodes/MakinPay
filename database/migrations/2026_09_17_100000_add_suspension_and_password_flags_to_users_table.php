<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-side account controls introduced with the User Manage redesign:
 * a timed suspension (reason, internal note, auto-lift time) and a flag
 * forcing the user to choose a new password after an admin set a temporary
 * one. Every column is nullable or defaulted, so an upgraded installation
 * behaves exactly as before until an admin uses one of the new actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('users', 'suspended_until')) {
                $table->timestamp('suspended_until')->nullable()->after('suspended_at');
            }

            if (! Schema::hasColumn('users', 'suspension_reason')) {
                $table->string('suspension_reason', 100)->nullable()->after('suspended_until');
            }

            if (! Schema::hasColumn('users', 'suspension_note')) {
                $table->text('suspension_note')->nullable()->after('suspension_reason');
            }

            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('suspension_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $columns = array_filter(
                ['suspended_at', 'suspended_until', 'suspension_reason', 'suspension_note', 'must_change_password'],
                static fn (string $column): bool => Schema::hasColumn('users', $column)
            );

            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
