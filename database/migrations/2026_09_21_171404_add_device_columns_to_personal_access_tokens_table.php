<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Which phone a token was issued to.
         *
         * Sanctum stores a `name` and nothing else, which is enough when tokens
         * are minted by hand from a dashboard and never enough for an app. Two
         * things need these columns.
         *
         * The first is re-issue. An app that is reinstalled, or that signs in
         * again after its token expired, asks for a new token for the same
         * handset; without a stable device id the old row stays valid forever,
         * so a phone that was wiped and sold still holds a working credential.
         * DeviceTokenService revokes by device id before minting, which is only
         * possible if the id is on the row.
         *
         * The second is the "signed in on" list, and the ability to sign a lost
         * phone out from another one. A list of names a client chose is not
         * enough to act on; the platform, the app version and the address last
         * seen are what let someone recognise the device they are revoking.
         *
         * All nullable and all added, never backfilled. Tokens minted before
         * this existed genuinely have no device on record - the dashboard ones
         * never will - and writing a placeholder would put a fact on the row
         * nobody ever stated. Every consumer must treat a null device as "not
         * a managed app token" rather than as a match.
         *
         * issued_ip records where the token was minted, not where it was last
         * used: Sanctum updates last_used_at on every authenticated request, and
         * following it with an address would be a second write per request for a
         * value that rarely changes. 45 characters because that is the longest an IPv6
         * address with an IPv4 tail can be.
         */
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('personal_access_tokens', 'device_id')) {
                $table->string('device_id', 64)->nullable()->after('abilities');
            }

            if (! Schema::hasColumn('personal_access_tokens', 'device_name')) {
                $table->string('device_name', 120)->nullable()->after('device_id');
            }

            if (! Schema::hasColumn('personal_access_tokens', 'platform')) {
                $table->string('platform', 20)->nullable()->after('device_name');
            }

            if (! Schema::hasColumn('personal_access_tokens', 'app_version')) {
                $table->string('app_version', 20)->nullable()->after('platform');
            }

            if (! Schema::hasColumn('personal_access_tokens', 'issued_ip')) {
                $table->string('issued_ip', 45)->nullable()->after('last_used_at');
            }
        });

        // Revoking by device reads (owner, device), which is the only query
        // these columns add. Sanctum's own index covers the owner alone, so
        // this one carries the device id with it rather than standing on it.
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            if (! $this->hasIndex('personal_access_tokens_device_lookup_index')) {
                $table->index(['tokenable_id', 'device_id'], 'personal_access_tokens_device_lookup_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            if ($this->hasIndex('personal_access_tokens_device_lookup_index')) {
                $table->dropIndex('personal_access_tokens_device_lookup_index');
            }
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            foreach (['device_id', 'device_name', 'platform', 'app_version', 'issued_ip'] as $column) {
                if (Schema::hasColumn('personal_access_tokens', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Index existence is not part of Laravel's Schema facade, and an upgraded
     * installation may already carry this one from a partial run.
     */
    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('personal_access_tokens') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
