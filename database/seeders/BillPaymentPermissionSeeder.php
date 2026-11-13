<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the Bill Payments permissions without disturbing existing roles.
 *
 * PermissionTableSeeder cannot do this job on an upgrade: its syncPresetRoles()
 * replaces each preset role's permission set, which would discard whatever an
 * administrator had customised. This seeder only creates what is missing and
 * only grants it to super-admin, so it is safe to replay on every update —
 * which is what reconcile_seeders does with it.
 *
 * Without this, an existing installation would upgrade into a module whose
 * every admin route is guarded by a permission that does not exist in its
 * database, and whose new-payment alert reaches nobody.
 *
 * The labels match what PermissionTableSeeder writes for the same rows on a
 * fresh install, so the two seeders never rewrite each other's copy.
 */
class BillPaymentPermissionSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, display_name: string, description: string}>
     */
    private const PERMISSIONS = [
        [
            'name'         => 'bill-payment-list',
            'display_name' => 'Bill Payment List',
            'description'  => 'View the bill payment queue, payment history and detail, and the billers, categories and providers.',
        ],
        [
            'name'         => 'bill-payment-action',
            'display_name' => 'Bill Payment Action',
            'description'  => 'Claim bill payments, mark them paid or failed, and see the token a paid bill returned.',
        ],
        [
            'name'         => 'bill-payment-manage',
            'display_name' => 'Bill Payment Manage',
            'description'  => 'Create, edit, enable and delete billers, bill categories and bill payment providers.',
        ],
        [
            'name'         => 'bill-payment-notification',
            'display_name' => 'Bill Payment Notification',
            'description'  => 'Receive an admin notification when a customer submits a bill payment.',
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
            'category'              => 'bill-payment',
            'display_name'          => $definition['display_name'],
            'description'           => $definition['description'],
            'category_display_name' => 'Bill Payments',
            'category_icon'         => 'receipt',
            'category_summary'      => 'Queue, billers, providers',
            'category_description'  => 'Bill payment queue, billers, categories, provider fees and status.',
        ];

        return array_filter(
            $candidates,
            static fn (string $value, string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
