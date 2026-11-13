<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the Mass Payments permissions without disturbing existing roles.
 *
 * PermissionTableSeeder cannot do this job on an upgrade: its syncPresetRoles()
 * replaces each preset role's permission set, which would discard whatever an
 * administrator had customised. This seeder only creates what is missing and
 * only grants it to super-admin, so it is safe to replay on every update —
 * which is what reconcile_seeders does with it.
 *
 * Without this, an existing installation would upgrade into a module whose
 * every admin route is guarded by a permission that does not exist in its
 * database, and whose held-batch alert reaches nobody.
 *
 * The labels match what PermissionTableSeeder writes for the same rows on a
 * fresh install, so the two seeders never rewrite each other's copy.
 */
class MassPaymentPermissionSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, display_name: string, description: string}>
     */
    public const PERMISSIONS = [
        [
            'name'         => 'mass-payment-list',
            'display_name' => 'Mass Payment List',
            'description'  => 'View mass payment batches, their rows, events and reports.',
        ],
        [
            'name'         => 'mass-payment-action',
            'display_name' => 'Mass Payment Action',
            'description'  => 'Resume held mass payment batches, cancel their remaining rows with a refund, record manual payouts, ask a provider for status, and reveal payout account details.',
        ],
        [
            'name'         => 'mass-payment-manage',
            'display_name' => 'Mass Payment Manage',
            'description'  => 'Close held mass payment batches after review, which settles the batch and moves no money. Also mark an unresolved payout paid with evidence, or confirm it was not paid.',
        ],
        [
            'name'         => 'mass-payment-notification',
            'display_name' => 'Mass Payment Notification',
            'description'  => 'Receive an admin notification when a mass payment batch is held for review.',
        ],
        [
            'name'         => 'mass-payment-approve',
            'display_name' => 'Mass Payment Approve',
            'description'  => 'Approve or reject mass payout batches waiting for approval. Rejecting returns the reserved money to the owner\'s wallet.',
        ],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $table   = config('permission.table_names.permissions', 'permissions');
        $granted = collect();

        foreach (self::PERMISSIONS as $definition) {
            $permission = Permission::firstOrCreate(
                [
                    'name'       => $definition['name'],
                    'guard_name' => 'admin',
                ],
                $this->optionalColumns($table, $definition)
            );

            /*
             * The descriptive columns are system-owned catalog labels, not
             * administrator content, so they are kept in step with this file
             * rather than only filled when blank. PermissionTableSeeder does
             * the same through its upsert. The permission name and the role
             * grants are never touched.
             */
            if (! $permission->wasRecentlyCreated) {
                $canonical = $this->optionalColumns($table, $definition);

                $drifted = array_filter(
                    $canonical,
                    static fn (string $value, string $column): bool => $permission->{$column} !== $value,
                    ARRAY_FILTER_USE_BOTH
                );

                if ($drifted !== []) {
                    $permission->forceFill($drifted)->save();
                }
            }

            $granted->push($permission);
        }

        $superRole = Role::query()
            ->where('guard_name', 'admin')
            ->where('name', 'super-admin')
            ->first();

        if ($superRole instanceof Role) {
            $superRole->givePermissionTo($granted->all());
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Only the descriptive columns this installation actually has. An older
     * database without them must not fail the insert.
     *
     * @param  array{name: string, display_name: string, description: string} $definition
     * @return array<string, string>
     */
    private function optionalColumns(string $table, array $definition): array
    {
        $candidates = [
            'category'              => 'mass-payment',
            'display_name'          => $definition['display_name'],
            'description'           => $definition['description'],
            'category_display_name' => 'Mass Payments',
            'category_icon'         => 'users',
            'category_summary'      => 'Batches, review, reports',
            'category_description'  => 'Mass payment batches, held and stalled batch review, refunds of remaining rows, and limits.',
        ];

        return array_filter(
            $candidates,
            static fn (string $value, string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
