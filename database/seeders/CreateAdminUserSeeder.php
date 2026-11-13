<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CreateAdminUserSeeder extends Seeder
{
    /**
     * Ensure a usable super-admin exists for local development.
     *
     * Credentials are read from the LOCAL .env (DEMO_ADMIN_EMAIL /
     * DEMO_ADMIN_PASSWORD) so no developer credentials are hardcoded in the
     * committed source. On a fresh client install the web installer creates
     * the admin from the buyer's own form input instead — this seeder is a
     * local development convenience.
     *
     * It is idempotent and NON-destructive: it upserts a single admin by
     * email rather than truncating the table, so running it can never wipe an
     * admin an installer/buyer already created.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::query()->firstOrCreate(
            ['guard_name' => 'admin', 'name' => 'super-admin'],
            ['description' => 'Full platform access with every permission.'],
        );

        $email    = (string) (config('app.demo_admin_email') ?: env('DEMO_ADMIN_EMAIL', 'admin@example.com'));
        $password = (string) (config('app.demo_admin_password') ?: env('DEMO_ADMIN_PASSWORD', Str::random(16)));

        $admin = Admin::query()->firstOrNew(['email' => $email]);

        $admin->forceFill([
            'name'              => $admin->name ?: 'Super Admin',
            'email'             => $email,
            'email_verified_at' => now(),
            // The Admin model casts `password` to `hashed`; assign the plain
            // value and let the cast hash it exactly once.
            'password' => $password,
            'status'   => true,
        ])->save();

        $admin->syncRoles(['super-admin']);

        if (! config('app.demo_admin_password') && ! env('DEMO_ADMIN_PASSWORD')) {
            Log::warning('CreateAdminUserSeeder generated a random admin password. Set DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD in .env to control it.', [
                'admin_email' => $email,
            ]);
        }
    }
}
