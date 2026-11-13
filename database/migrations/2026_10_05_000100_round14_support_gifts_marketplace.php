<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 14 of the app: a support ticket can name the transaction it is
 * about, so the conversation can show that transaction's fate beside the
 * messages, and a vendor can say where they are, which the directory rows
 * show under the name. Both guarded; an installation that already has
 * either column is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('tickets', 'transaction_ref')) {
                $table->string('transaction_ref', 64)->nullable()->after('attachment')->index();
            }
        });

        Schema::table('vendors', function (Blueprint $table): void {
            if (! Schema::hasColumn('vendors', 'location')) {
                $table->string('location', 120)->nullable()->after('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            if (Schema::hasColumn('vendors', 'location')) {
                $table->dropColumn('location');
            }
        });

        Schema::table('tickets', function (Blueprint $table): void {
            if (Schema::hasColumn('tickets', 'transaction_ref')) {
                $table->dropColumn('transaction_ref');
            }
        });
    }
};
