<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * When the customer closed their own account. The row stays - the ledger
     * and KYC records still point at it - but its personal fields are wiped
     * and it can never sign in again; this marks it as closed rather than
     * merely switched off by an administrator.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'closed_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'closed_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('closed_at');
        });
    }
};
