<?php

declare(strict_types=1);

namespace App\Console\Commands\Installation;

use App\Support\Installation\Preflight;
use Illuminate\Console\Command;

/**
 * Report whether this server is ready to run MakinPay.
 *
 * Read-only by design: it inspects PHP, the environment, the application key,
 * database connectivity, file permissions and the installation marker, and
 * prints a verdict. It never migrates, never seeds, and never writes.
 *
 * This is the command to point an operator at when a deployment will not boot.
 * It exits non-zero when a blocking failure is present, so it can be used in a
 * deployment pipeline as a gate.
 */
class InstallCheckCommand extends Command
{
    protected $signature = 'makinpay:install:check
                            {--json : Emit machine-readable JSON instead of a table.}';

    protected $description = 'Check that this server satisfies MakinPay requirements and whether it is already installed';

    public function __construct(private readonly Preflight $preflight)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $checks = $this->preflight->run();
        $worst  = $this->preflight->worst($checks);
        $blocked = $this->preflight->hasBlockingFailure($checks);

        if ((bool) $this->option('json')) {
            $this->output->writeln((string) json_encode([
                'installed' => $this->preflight->isInstalled(),
                'verdict'   => $worst,
                'blocking'  => $blocked,
                'checks'    => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $blocked ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('MakinPay installation check');
        $this->newLine();

        $rows = [];

        foreach ($checks as $check) {
            $rows[] = [
                match ($check['status']) {
                    Preflight::OK      => '<fg=green>OK</>',
                    Preflight::WARN    => '<fg=yellow>WARN</>',
                    Preflight::FAIL    => '<fg=red>FAIL</>',
                    default            => '<fg=gray>?</>',
                },
                $check['label'],
                $check['detail'],
            ];
        }

        $this->table(['', 'Check', 'Result'], $rows);

        $remedies = array_values(array_filter(array_map(
            static fn (array $check): string => (string) ($check['remedy'] ?? ''),
            $checks
        )));

        if ($remedies !== []) {
            $this->newLine();
            $this->components->warn('Notes');
            foreach ($remedies as $remedy) {
                $this->line('  • '.$remedy);
            }
        }

        $this->newLine();

        if ($blocked) {
            $this->components->error('This server is NOT ready. Resolve the FAIL entries above.');

            return self::FAILURE;
        }

        if (! $this->preflight->isInstalled()) {
            $this->components->warn('Server is ready, but MakinPay is not installed yet. Run: php artisan makinpay:install');
        } else {
            $marker = $this->preflight->marker();

            $this->components->twoColumnDetail('Installation', '<fg=green>complete</>');
            if ($marker !== null) {
                $this->components->twoColumnDetail('Installed at', (string) ($marker['installed_at'] ?? 'unknown'));
                $this->components->twoColumnDetail('Version', (string) ($marker['version'] ?? 'unknown'));
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
