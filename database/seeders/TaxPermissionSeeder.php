<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the tax rule permissions (GF6) without disturbing existing roles.
 *
 * PermissionTableSeeder cannot do this job on an upgrade: its syncPresetRoles()
 * replaces each preset role's permission set, which would discard whatever an
 * administrator had customised. This seeder only creates what is missing and
 * only grants it to super-admin, so it is safe to replay on every update —
 * which is what reconcile_seeders does with it.
 *
 * Without this, an existing installation would upgrade into a module whose
 * every admin route is guarded by a permission that does not exist in its
 * database.
 *
 * The labels match what PermissionTableSeeder writes for the same rows on a
 * fresh install, so the two seeders never rewrite each other's copy.
 */
class TaxPermissionSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, display_name: string, description: string}>
     */
    private const PERMISSIONS = [
        [
            'name'         => 'tax-rule-list',
            'display_name' => 'Tax Rule List',
            'description'  => 'View the tax and levy rules and the tax collected under them.',
        ],
        [
            'name'         => 'tax-rule-manage',
            'display_name' => 'Tax Rule Manage',
            'description'  => 'Create, edit, switch on or off and delete tax and levy rules. A rule changes what customers are charged from the next transaction.',
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
            'category'              => 'tax',
            'display_name'          => $definition['display_name'],
            'description'           => $definition['description'],
            'category_display_name' => 'Taxes & Levies',
            'category_icon'         => 'receipt',
            'category_summary'      => 'Rules, collected tax',
            'category_description'  => 'Tax and levy rules charged on transactions, and the tax they collected.',
        ];

        return array_filter(
            $candidates,
            static fn (string $value, string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
