<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission that installs, enables, disables and uninstalls add-ons.
 *
 * Kept apart from feature-manage: turning a feature on is a setting, while
 * installing an add-on runs a package's code and migrations on this server.
 * Additive and safe to replay on every update - granted to super-admin only,
 * never syncPermissions(), so a customised role is never touched.
 */
class AddonPermissionSeeder extends Seeder
{
    public const PERMISSION = 'addon-manage';

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $table      = config('permission.table_names.permissions', 'permissions');
        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'admin'],
            $this->optionalColumns($table),
        );

        $superRole = Role::query()->where('guard_name', 'admin')->where('name', 'super-admin')->first();

        if ($superRole instanceof Role) {
            $superRole->givePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Only the descriptive columns this installation actually has; grouped
     * with feature-list / feature-manage in the role editor.
     *
     * @return array<string, string>
     */
    private function optionalColumns(string $table): array
    {
        $candidates = [
            'category'              => 'feature',
            'display_name'          => 'Addon Manage',
            'description'           => 'Install, enable, disable and uninstall add-ons, and activate their licenses.',
            'category_display_name' => 'Feature Management',
            'category_icon'         => 'feature-management',
            'category_summary'      => 'Toggles, rules',
            'category_description'  => 'Feature switches, rollout rules, and panel access controls.',
        ];

        return array_filter(
            $candidates,
            static fn (string $value, string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
