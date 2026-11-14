<?php

declare(strict_types=1);

namespace App\Support\Installation;

use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Create the first administrator.
 *
 * Separated from the install command so it can be re-run deliberately on an
 * existing installation (`makinpay:admin:create`) without touching schema,
 * seeders, or the installation marker.
 *
 * Security properties, in order of importance:
 *
 *  - The password is only ever accepted as a string in memory. It is never
 *    read from a command-line option, never echoed, and never written to a log.
 *  - It is hashed exactly once. The Admin model casts `password` to `hashed`,
 *    so the service assigns the plain value and lets the cast do the work.
 *    Assigning an already-hashed value would hash it twice and lock the
 *    operator out.
 *  - It is validated with the same password rules the retired web installer
 *    used, so the policy does not loosen by moving to the CLI.
 *  - An existing admin is never silently overwritten. Callers must pass
 *    `overwrite` explicitly, which the commands only do after confirming.
 */
class AdministratorProvisioner
{
    public const SUPER_ADMIN_ROLE = 'super-admin';

    public const ROLE_GUARD = 'admin';

    /**
     * Password policy. Mirrors the released installer's rule set
     * (InstallApplicationRequest): at least 8 characters, mixed case, numbers.
     */
    public const MIN_PASSWORD_LENGTH = 8;

    /**
     * @return list<string> Validation failure messages, empty when valid.
     */
    public function validate(string $name, string $email, string $password, string $confirmation): array
    {
        $validator = Validator::make(
            [
                'name'                  => $name,
                'email'                 => $email,
                'password'              => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'name'     => ['required', 'string', 'max:120'],
                'email'    => ['required', 'email:rfc', 'max:255'],
                'password' => [
                    'required',
                    'confirmed',
                    'min:'.self::MIN_PASSWORD_LENGTH,
                    'regex:/[a-z]/',
                    'regex:/[A-Z]/',
                    'regex:/[0-9]/',
                ],
            ],
            [
                'password.confirmed' => 'The two passwords do not match.',
                'password.min'       => 'The password must be at least :min characters long.',
                'password.regex'     => 'The password must contain lower-case, upper-case and numeric characters.',
            ]
        );

        return $validator->errors()->all();
    }

    /**
     * The administrator table must exist before anything else happens.
     */
    public function schemaReady(): bool
    {
        try {
            return Schema::hasTable('admins');
        } catch (Throwable) {
            return false;
        }
    }

    public function findByEmail(string $email): ?Admin
    {
        return Admin::query()->where('email', mb_strtolower(trim($email)))->first();
    }

    /**
     * Create or (when explicitly permitted) update a super administrator.
     *
     * Returns a small result array rather than throwing for the ordinary
     * "already exists" case, so callers can report it as a refusal instead of
     * an error.
     *
     * @return array{created: bool, admin: Admin|null, reason: string}
     */
    public function provision(
        string $name,
        string $email,
        string $password,
        bool $overwrite = false,
    ): array {
        if (! $this->schemaReady()) {
            return ['created' => false, 'admin' => null, 'reason' => 'The "admins" table does not exist. Run migrations first.'];
        }

        $email = mb_strtolower(trim($email));
        $name  = trim($name);

        // Defence in depth. Validation belongs to the caller and runs first,
        // but the provisioner is a security boundary: a future caller (a
        // seeder, a repair command, a controller) must not be able to write a
        // weak administrator password by forgetting to validate.
        $policyErrors = $this->validate($name, $email, $password, $password);

        if ($policyErrors !== []) {
            return ['created' => false, 'admin' => null, 'reason' => implode(' ', $policyErrors)];
        }

        $existing = $this->findByEmail($email);

        if ($existing instanceof Admin && ! $overwrite) {
            return [
                'created' => false,
                'admin'   => $existing,
                'reason'  => 'An administrator with this email already exists. Re-run with --overwrite to reset its password deliberately.',
            ];
        }

        // Roles must exist before the assignment below. PermissionTableSeeder
        // normally created them; creating the role here keeps this command
        // usable on an installation where seeding was skipped.
        $role = $this->ensureSuperAdminRole();

        $admin = $existing instanceof Admin ? $existing : new Admin();

        // Assign the PLAIN password: the `hashed` cast on the model performs
        // the single hash. Never pass Hash::make() output here.
        $admin->forceFill([
            'name'              => $name,
            'email'             => $email,
            'email_verified_at' => now(),
            'password'          => $password,
            'status'            => true,
        ])->save();

        $admin->syncRoles([$role->name]);

        return ['created' => ! ($existing instanceof Admin), 'admin' => $admin->refresh(), 'reason' => ''];
    }

    /**
     * Whether any administrator already exists. Used by the installer to
     * avoid creating a second first-admin on a re-run against a populated
     * database.
     */
    public function hasAnyAdministrator(): bool
    {
        try {
            return $this->schemaReady() && Admin::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function ensureSuperAdminRole(): Role
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable) {
            // The registrar is optional for the create itself.
        }

        $role = Role::query()->firstOrCreate(
            ['guard_name' => self::ROLE_GUARD, 'name' => self::SUPER_ADMIN_ROLE],
            ['description' => 'Full platform access with every permission.']
        );

        // Only grant the full permission set when the role carries none yet.
        // PermissionTableSeeder owns the canonical set; this is a safety net
        // for an installation that never ran it, and must not clobber a role
        // an administrator has deliberately narrowed.
        if ($role->permissions()->count() === 0) {
            $permissions = DB::table('permissions')
                ->where('guard_name', self::ROLE_GUARD)
                ->pluck('name')
                ->all();

            if ($permissions !== []) {
                $role->syncPermissions($permissions);
            }
        }

        return $role;
    }

    /**
     * Generate a strong random password for non-interactive use (`--no-interaction`
     * on a throwaway environment). Returned to the caller for one-time display;
     * never logged.
     */
    public function generatePassword(int $length = 20): string
    {
        // Str::password guarantees the mixed-case + numeric composition the
        // validator above requires.
        return Str::password($length, symbols: true);
    }
}
