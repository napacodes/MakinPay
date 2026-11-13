<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What a payout batch (`kind = 'payout'`) needs on top of a wallet batch: the
 * one withdraw method every line is sent through, the driver that method
 * resolved to, and whether an admin has to approve the batch before anything
 * is sent.
 *
 *   withdraw_method_id    the method the batch was built on. Deliberately not
 *                         a foreign key: the method's terms are snapshotted
 *                         into `metadata.payout`, so deleting or editing the
 *                         method never breaks the history or the
 *                         withdraw-method admin.
 *   payout_driver         manual | bitnob | stripe_connect, frozen at draft.
 *   approval_required     the approval decision taken at confirm.
 *   approved_by_admin_id  who approved it; null once that admin is deleted.
 *   approved_at           when.
 *
 * `kind` had no index; (kind, status) serves every queue that now reads one
 * kind at a time.
 *
 * Added, never backfilled: a GF4 wallet batch keeps null payout columns and
 * `approval_required = false`, so nothing it does changes. Every addition is
 * guarded so a partial earlier run, or a second run, is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'mass_payment_batches';

    private const KIND_STATUS_INDEX = 'mass_payment_batches_kind_status_index';

    private const METHOD_INDEX = 'mass_payment_batches_withdraw_method_id_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'withdraw_method_id')) {
                $table->unsignedBigInteger('withdraw_method_id')->nullable()->after('kind');
            }

            if (! Schema::hasColumn(self::TABLE, 'payout_driver')) {
                $table->string('payout_driver', 32)->nullable()->after('withdraw_method_id');
            }

            if (! Schema::hasColumn(self::TABLE, 'approval_required')) {
                $table->boolean('approval_required')->default(false)->after('payout_driver');
            }

            if (! Schema::hasColumn(self::TABLE, 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approval_required');
            }
        });

        if (! Schema::hasColumn(self::TABLE, 'approved_by_admin_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreignId('approved_by_admin_id')->nullable()->after('approved_at')->constrained('admins')->nullOnDelete();
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasIndex(self::TABLE, self::METHOD_INDEX)) {
                $table->index('withdraw_method_id', self::METHOD_INDEX);
            }

            if (! Schema::hasIndex(self::TABLE, self::KIND_STATUS_INDEX)) {
                $table->index(['kind', 'status'], self::KIND_STATUS_INDEX);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach ([self::KIND_STATUS_INDEX, self::METHOD_INDEX] as $index) {
                if (Schema::hasIndex(self::TABLE, $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        if (Schema::hasColumn(self::TABLE, 'approved_by_admin_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('approved_by_admin_id');
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (['withdraw_method_id', 'payout_driver', 'approval_required', 'approved_at'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
