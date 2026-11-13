<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Until now the table recorded arrivals only: a wrong password left no
        // trace anywhere in the schema, so nobody could see an account being
        // guessed at. The flag turns the same table into a record of attempts.
        //
        // The default is deliberately `true`. Every row that already exists
        // was written by the successful-login listener, so on an upgrade the
        // existing history keeps meaning exactly what it meant before.
        Schema::table('login_activities', function (Blueprint $table): void {
            $table->boolean('succeeded')->default(true)->after('user_id');

            // The risk panel asks one question of this table — "how many
            // failures on this account since yesterday" — and the index is
            // that question in column order.
            $table->index(['user_id', 'succeeded', 'login_at'], 'login_activities_attempt_index');
        });
    }

    public function down(): void
    {
        Schema::table('login_activities', function (Blueprint $table): void {
            $table->dropIndex('login_activities_attempt_index');
            $table->dropColumn('succeeded');
        });
    }
};
