<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What a payout line needs on top of a wallet line: where it stands with the
 * provider, the keys that bind a provider answer to exactly this line, the
 * encrypted account it is sent to, and the reconcile schedule.
 *
 *   dispatch_state        null on a wallet line, which is how GF4 rows stay
 *                         untouched; reserved | submitting | awaiting_operator
 *                         | awaiting_provider | needs_review | settled |
 *                         refunded on a payout line.
 *   payout_driver         the batch's driver, denormalised so the provider
 *                         reference can be unique per driver.
 *   provider_request_id   our idempotency key with the provider (`mpo_...`),
 *                         set once at confirm and never regenerated.
 *   provider_reference    the provider's own id for the payout.
 *   provider_status       the provider's last status word, as reported.
 *   payout_account        the account fields, encrypted with APP_KEY through
 *                         the model's `encrypted:array` cast. Text, because
 *                         the ciphertext is far longer than the plaintext.
 *   payout_account_hash   an HMAC of the normalised account, so duplicate
 *                         accounts are found without decrypting anything.
 *   submitted_at          null means the provider was never asked, which is
 *                         the only state a line may be refunded from blindly.
 *   last_checked_at, next_check_at, last_check_outcome, check_attempts
 *                         the read-only reconcile schedule.
 *   settled_at            when the line reached a terminal dispatch state.
 *   failure_reason        the sanitised reason a provider or operator gave.
 *   resolved_by_admin_id  the admin who decided a line under review.
 *
 * Unique keys carry the exactly-once story for external payouts:
 *   - provider_request_id: one provider request per line, across batches;
 *   - (payout_driver, provider_reference): one provider payout can settle at
 *     most one line. Null references never collide.
 *
 * (dispatch_state, next_check_at) is what the reconcile command reads, so the
 * due lines are found without a scan.
 *
 * Added, never backfilled. Every addition is guarded so a partial earlier
 * run, or a second run, is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'mass_payment_rows';

    private const REQUEST_UNIQUE = 'mass_payment_rows_provider_request_id_unique';

    private const REFERENCE_UNIQUE = 'mass_payment_rows_driver_reference_unique';

    private const ACCOUNT_HASH_INDEX = 'mass_payment_rows_payout_account_hash_index';

    private const NEXT_CHECK_INDEX = 'mass_payment_rows_dispatch_next_check_index';

    /** @var list<string> */
    private const PLAIN_COLUMNS = [
        'dispatch_state',
        'payout_driver',
        'provider_request_id',
        'provider_reference',
        'provider_status',
        'payout_account',
        'payout_account_hash',
        'submitted_at',
        'last_checked_at',
        'next_check_at',
        'settled_at',
        'last_check_outcome',
        'check_attempts',
        'failure_reason',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $has = fn (string $column): bool => Schema::hasColumn(self::TABLE, $column);

            if (! $has('dispatch_state')) {
                $table->string('dispatch_state', 32)->nullable()->after('status');
            }

            if (! $has('payout_driver')) {
                $table->string('payout_driver', 32)->nullable()->after('dispatch_state');
            }

            if (! $has('provider_request_id')) {
                $table->string('provider_request_id', 64)->nullable()->after('payout_driver');
            }

            if (! $has('provider_reference')) {
                $table->string('provider_reference', 128)->nullable()->after('provider_request_id');
            }

            if (! $has('provider_status')) {
                $table->string('provider_status', 40)->nullable()->after('provider_reference');
            }

            if (! $has('payout_account')) {
                $table->text('payout_account')->nullable()->after('provider_status');
            }

            if (! $has('payout_account_hash')) {
                $table->char('payout_account_hash', 64)->nullable()->after('payout_account');
            }

            if (! $has('submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('payout_account_hash');
            }

            if (! $has('last_checked_at')) {
                $table->timestamp('last_checked_at')->nullable()->after('submitted_at');
            }

            if (! $has('next_check_at')) {
                $table->timestamp('next_check_at')->nullable()->after('last_checked_at');
            }

            if (! $has('settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('next_check_at');
            }

            if (! $has('last_check_outcome')) {
                $table->string('last_check_outcome', 16)->nullable()->after('settled_at');
            }

            if (! $has('check_attempts')) {
                $table->unsignedSmallInteger('check_attempts')->default(0)->after('last_check_outcome');
            }

            if (! $has('failure_reason')) {
                $table->string('failure_reason', 500)->nullable()->after('check_attempts');
            }
        });

        if (! Schema::hasColumn(self::TABLE, 'resolved_by_admin_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreignId('resolved_by_admin_id')->nullable()->after('failure_reason')->constrained('admins')->nullOnDelete();
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasIndex(self::TABLE, self::REQUEST_UNIQUE)) {
                $table->unique('provider_request_id', self::REQUEST_UNIQUE);
            }

            if (! Schema::hasIndex(self::TABLE, self::REFERENCE_UNIQUE)) {
                $table->unique(['payout_driver', 'provider_reference'], self::REFERENCE_UNIQUE);
            }

            if (! Schema::hasIndex(self::TABLE, self::ACCOUNT_HASH_INDEX)) {
                $table->index('payout_account_hash', self::ACCOUNT_HASH_INDEX);
            }

            if (! Schema::hasIndex(self::TABLE, self::NEXT_CHECK_INDEX)) {
                $table->index(['dispatch_state', 'next_check_at'], self::NEXT_CHECK_INDEX);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach ([self::REQUEST_UNIQUE, self::REFERENCE_UNIQUE] as $unique) {
                if (Schema::hasIndex(self::TABLE, $unique)) {
                    $table->dropUnique($unique);
                }
            }

            foreach ([self::ACCOUNT_HASH_INDEX, self::NEXT_CHECK_INDEX] as $index) {
                if (Schema::hasIndex(self::TABLE, $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        if (Schema::hasColumn(self::TABLE, 'resolved_by_admin_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('resolved_by_admin_id');
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (self::PLAIN_COLUMNS as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
