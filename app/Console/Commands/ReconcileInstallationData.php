<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Bring an installation's reference data up to what the current code expects.
 *
 * Runs after every install and every update, unattended. Two things reach an
 * installation short of data: a fresh install that never took the MySQL dump
 * path (SQLite, or a manual `migrate`), and an existing site updating to a
 * version whose new code reads rows its database has never held. Either way the
 * administrator should not have to know a seeder exists.
 *
 * Only seeders listed in `installer.reconcile_seeders` run here, and that list
 * is restricted to strictly additive ones: they create what is missing and
 * leave anything already present untouched. The feature seeders in
 * `installer.core_seeders` rewrite channel copy on every run, so they belong to
 * first install only — replaying them on update would discard wording an
 * administrator edited from the admin panel.
 *
 * A failure here is reported, never fatal: reference data lagging is a
 * degraded install, but a half-applied update is a broken one.
 */
class ReconcileInstallationData extends Command
{
    protected $signature = 'install:reconcile
                            {--dry-run : Report what would change without writing.}';

    protected $description = 'Seed missing reference data and bind legacy transactions after an install or update';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach ((array) config('installer.reconcile_seeders', []) as $seeder) {
            $this->runStep('Reconciling '.class_basename($seeder), function () use ($seeder, $dryRun): void {
                if ($dryRun) {
                    return;
                }

                Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
            });
        }

        $this->runStep('Binding legacy transactions to their gateway', function () use ($dryRun): void {
            Artisan::call('payments:backfill-gateway-code', $dryRun ? ['--dry-run' => true] : []);
        });

        return self::SUCCESS;
    }

    private function runStep(string $label, callable $step): void
    {
        try {
            $step();
            $this->components->task($label, fn (): bool => true);
        } catch (Throwable $e) {
            $this->components->task($label, fn (): bool => false);
            $this->components->warn($label.' failed: '.$e->getMessage());
        }
    }
}
