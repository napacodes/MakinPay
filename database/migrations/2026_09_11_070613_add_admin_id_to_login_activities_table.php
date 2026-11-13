<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give the login history somewhere to record an administrator.
     *
     * Both listeners deliberately bail on anything but the `web` guard,
     * because `user_id` points at `users` and an Admin has no row there. The
     * team-members screen needs "when was this person last here, and from
     * where", so administrators get their own column rather than sharing one
     * whose meaning every existing screen already depends on.
     *
     * Existing rows keep `admin_id` null, so a customer query that adds
     * nothing still returns exactly what it returned yesterday.
     */
    public function up(): void
    {
        if (Schema::hasColumn('login_activities', 'admin_id')) {
            return;
        }

        Schema::table('login_activities', function (Blueprint $table): void {
            $table->foreignId('admin_id')->nullable()->after('user_id')->constrained('admins')->nullOnDelete();

            /* "When was this administrator last here" in column order. */
            $table->index(['admin_id', 'succeeded', 'login_at'], 'login_activities_admin_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('login_activities', 'admin_id')) {
            return;
        }

        Schema::table('login_activities', function (Blueprint $table): void {
            $table->dropIndex('login_activities_admin_index');
            $table->dropConstrainedForeignId('admin_id');
        });
    }
};
