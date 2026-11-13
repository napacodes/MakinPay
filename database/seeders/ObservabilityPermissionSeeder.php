<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the Error Tracking permissions without disturbing existing roles.
 *
 * PermissionTableSeeder cannot do this job on an upgrade: its syncPresetRoles()
 * replaces each preset role's permission set, which would discard whatever an
 * administrator had customised. This seeder only creates what is missing and
 * only grants it to super-admin, so it is safe to replay on every update —
 * which is what reconcile_seeders does with it.
 *
 * Without this, an existing installation would upgrade into a module whose
 * every route is guarded by a permission that does not exist in its database.
 */
class ObservabilityPermissionSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, display_name: string, description: string}>
     */
    private const PERMISSIONS = [
        [
            'name'         => 'observability-list',
            'display_name' => 'System Health List',
            'description'  => 'View captured errors, the issue list and error detail.',
        ],
        [
            'name'         => 'observability-manage',
            'display_name' => 'System Health Manage',
            'description'  => 'Resolve, ignore and assign errors, and configure notification channels.',
        ],
        [
            'name'         => 'observability-export',
            'display_name' => 'System Health Export',
            'description'  => 'Export an error as a debug report.',
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
             * the same through its upsert; without it, reworded copy would
             * stay stale forever on every installation that already has the
             * row. The permission name and the role grants are never touched.
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
            'category'              => 'observability',
            'display_name'          => $definition['display_name'],
            'description'           => $definition['description'],
            'category_display_name' => 'Error Tracking',
            'category_icon'         => 'observability',
            'category_summary'      => 'Errors, triage, alerts',
            'category_description'  => 'Captured errors, triage and export, and the channels alerts are delivered to.',
        ];

        return array_filter(
            $candidates,
            static fn (string $value, string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
