<?php

declare(strict_types=1);

namespace App\Console\Commands\Installation;

use App\Support\Installation\AdministratorProvisioner;
use Illuminate\Console\Command;

/**
 * Create an administrator account.
 *
 * Usable both during initial setup and later, on a live installation, to add
 * another administrator or deliberately reset one's password.
 *
 * The password is only ever read interactively (hidden), generated, or — for
 * automation — supplied through the MAKINPAY_ADMIN_PASSWORD environment
 * variable of the invoking process. It is never taken from a command-line
 * option, because option values are visible in shell history and in `ps`
 * output to every user on the host.
 */
class AdminCreateCommand extends Command
{
    protected $signature = 'makinpay:admin:create
                            {--name= : Administrator display name.}
                            {--email= : Administrator email address.}
                            {--overwrite : Reset the password of an existing administrator with this email.}';

    protected $description = 'Create (or deliberately reset) a MakinPay administrator account';

    public function __construct(private readonly AdministratorProvisioner $provisioner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->provisioner->schemaReady()) {
            $this->components->error('The "admins" table does not exist. Run migrations first (php artisan migrate).');

            return self::FAILURE;
        }

        $interactive = $this->input->isInteractive() && ! (bool) $this->option('no-interaction');

        $name  = (string) ($this->option('name') ?? '');
        $email = (string) ($this->option('email') ?? '');

        if ($interactive) {
            $name = $name !== '' ? $name : (string) $this->ask('Name', 'Super Admin');
            $email = $email !== '' ? $email : (string) $this->ask('Email address');
        }

        if ($email === '') {
            // Environment fallback keeps automation possible without ever
            // putting a credential on the argument list.
            $email = (string) (getenv('MAKINPAY_ADMIN_EMAIL') ?: '');

            if ($email === '') {
                $this->components->error('An email address is required. Pass --email, or set MAKINPAY_ADMIN_EMAIL.');

                return self::FAILURE;
            }
        }

        if (! $this->provisioner->schemaReady()) {
            $this->components->error('The "admins" table is missing.');

            return self::FAILURE;
        }

        $existing = $this->provisioner->findByEmail($email);

        if ($existing !== null && ! (bool) $this->option('overwrite')) {
            $this->components->error('An administrator with '.$email.' already exists. Pass --overwrite to reset its password deliberately.');

            return self::FAILURE;
        }

        if ($existing !== null) {
            if ($interactive && ! $this->confirm('Reset the password for '.$email.'?', false)) {
                $this->components->warn('Cancelled.');

                return self::SUCCESS;
            }
        }

        [$password, $generated] = $this->resolvePassword($interactive);

        if ($password === null) {
            return self::FAILURE;
        }

        if ($interactive) {
            $confirmation = (string) $this->secret('Confirm password');
        } else {
            // Non-interactive: the confirmation is the same value by
            // definition, so the `confirmed` rule is satisfied while every
            // strength rule still applies. Skipping validation here would let
            // an automation caller set a one-character password.
            $confirmation = (string) $password;
        }

        $errors = $this->provisioner->validate($name !== '' ? $name : 'Super Admin', $email, (string) $password, $confirmation);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $result = $this->provisioner->provision(
            $name !== '' ? $name : 'Super Admin',
            $email,
            $password,
            (bool) $this->option('overwrite'),
        );

        if ($result['admin'] === null) {
            $this->components->error($result['reason']);

            return self::FAILURE;
        }

        if ($generated) {
            $this->newLine();
            $this->components->warn('Generated password — shown once, store it now');
            $this->line('  <fg=yellow>'.$password.'</>');
            $this->newLine();
        }

        $this->components->twoColumnDetail(
            'Administrator',
            ($result['created'] ? '<fg=green>created</>' : '<fg=yellow>password reset</>').' '.$result['admin']->email
        );

        return self::SUCCESS;
    }

    /**
     * @return array{0: string|null, 1: bool} Password and whether it was generated.
     */
    private function resolvePassword(bool $interactive): array
    {
        $fromEnvironment = (string) (getenv('MAKINPAY_ADMIN_PASSWORD') ?: '');

        if ($fromEnvironment !== '') {
            return [$fromEnvironment, false];
        }

        if ($interactive) {
            $password = (string) $this->secret('Password (min 8, upper + lower + number)');

            if ($password === '') {
                $this->components->error('A password is required.');

                return [null, false];
            }

            return [$password, false];
        }

        return [$this->provisioner->generatePassword(), true];
    }
}
