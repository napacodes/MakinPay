<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the operations-dashboard permissions without disturbing existing roles.
 *
 * PermissionTableSeeder cannot do this job on an upgrade: its syncPresetRoles()
 * replaces each preset role's permission set, which would discard whatever an
 * administrator had customised. This seeder only creates what is missing and
 * only grants it to super-admin, so it is safe to replay on every update —
 * which is what reconcile_seeders does with it.
 *
 * Without this, an existing installation would upgrade into a dashboard whose
 * work queue, health chips and SLA modal are guarded by permissions that do
 * not exist in its database, and every one of them would silently disappear.
 */
class DashboardPermissionSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, display_name: string, description: string}>
     */
    private const PERMISSIONS = [
        [
            'name'         => 'dashboard-queue',
            'display_name' => 'Dashboard Queue',
            'description'  => 'See the pending review queues, their ageing against SLA, and the first-run setup checklist.',
        ],
        [
            'name'         => 'system-health',
            'display_name' => 'System Health',
            'description'  => 'See scheduler, queue worker, payment gateway and error-rate health on the dashboard.',
        ],
        [
            'name'         => 'risk-watch',
            'display_name' => 'Risk Watch',
            'description'  => 'See the risk and fraud signals panel on the dashboard.',
        ],
        [
            'name'         => 'dashboard-sla-manage',
            'display_name' => 'Dashboard SLA Manage',
            'description'  => 'Change the review SLA targets that drive the dashboard alert bar.',
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
            'category'              => 'dashboard',
            'display_name'          => $definition['display_name'],
            'description'           => $definition['description'],
            'category_display_name' => 'Dashboard',
            'category_icon'         => 'cil-speedometer',
            'category_summary'      => 'Stats, charts, wallets',
            'category_description'  => 'Dashboard metrics, wallet snapshots, growth charts, and latest platform activity.',
        ];

        return array_filter(
            $candidates,
            static fn (string $value, string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
