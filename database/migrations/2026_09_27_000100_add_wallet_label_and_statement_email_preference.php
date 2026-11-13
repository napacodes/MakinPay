<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additive columns for the app's wallet screen: a name the customer
 * gives a wallet, and whether they want the month's statement mailed.
 * Both nullable/defaulted, so an upgrade changes nothing until used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wallets', 'label')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->string('label', 40)->nullable()->after('uuid');
            });
        }

        if (! Schema::hasColumn('users', 'monthly_statement_email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('monthly_statement_email')->default(false)->after('display_currency');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wallets', 'label')) {
            Schema::table('wallets', fn (Blueprint $table) => $table->dropColumn('label'));
        }

        if (Schema::hasColumn('users', 'monthly_statement_email')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('monthly_statement_email'));
        }
    }
};
