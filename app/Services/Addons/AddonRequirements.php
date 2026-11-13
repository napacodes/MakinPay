<?php

declare(strict_types=1);

namespace App\Services\Addons;

use App\Enums\AddonStatus;
use App\Models\Addon;
use App\Services\BackgroundHealthService;
use Closure;

/**
 * What an add-on needs from this server, checked item by item.
 *
 * Install needs the code to run at all: a compatible core, the PHP
 * extensions, and any add-on it requires installed at a version it can use.
 * Enable needs everything, including that add-on running, and a running
 * scheduler and queue when the manifest asks for them - an add-on that
 * settles deposits from a scheduled scanner is worse than off when cron is
 * dead, because it looks on while nothing happens.
 */
final class AddonRequirements
{
    /**
     * @param (Closure(string): bool)|null $isExtensionLoaded defaults to extension_loaded
     */
    public function __construct(
        private readonly AddonRegistry $registry,
        private readonly BackgroundHealthService $health,
        private readonly ?Closure $isExtensionLoaded = null,
    ) {}

    /**
     * @return list<array{key: string, label: string, passed: bool, fix: string|null}>
     */
    public function check(AddonManifest $manifest, bool $includeRuntime = true): array
    {
        $isLoaded = $this->isExtensionLoaded ?? extension_loaded(...);

        $compatible = $this->registry->isCompatible($manifest);
        $checks     = [[
            'key'    => 'core',
            'label'  => sprintf('DigiKash %s or later', $manifest->requiresCore),
            'passed' => $compatible,
            'fix'    => $compatible ? null : sprintf('This site runs %s. Update DigiKash first.', $this->registry->coreVersion()),
        ]];

        foreach ($manifest->phpExtensions as $extension) {
            $loaded   = $isLoaded($extension);
            $checks[] = [
                'key'    => 'extension:'.$extension,
                'label'  => sprintf('PHP %s extension', $extension),
                'passed' => $loaded,
                'fix'    => $loaded ? null : sprintf('Enable the PHP %s extension in php.ini or your hosting panel, then restart PHP.', $extension),
            ];
        }

        foreach ($manifest->requiresAddons as $slug => $minimum) {
            $checks[] = $this->requiredAddon($slug, $minimum, mustRun: $includeRuntime);
        }

        foreach ($manifest->worksWith as $slug => $minimum) {
            $checks[] = $this->compatibleAddon($slug, $minimum);
        }

        if (! $includeRuntime) {
            return $checks;
        }

        if ($manifest->requiresCron) {
            $running  = $this->health->scheduler()['status'] === BackgroundHealthService::STATUS_OK;
            $checks[] = [
                'key'    => 'cron',
                'label'  => 'Scheduler (cron) running',
                'passed' => $running,
                'fix'    => $running ? null : sprintf('Add the cron entry "* * * * * cd %s && php artisan schedule:run >> /dev/null 2>&1" and wait a minute.', base_path()),
            ];
        }

        if ($manifest->requiresQueue) {
            $working  = $this->health->queue()['status'] !== BackgroundHealthService::STATUS_STALLED;
            $checks[] = [
                'key'    => 'queue',
                'label'  => 'Queue worker running',
                'passed' => $working,
                'fix'    => $working ? null : 'Start a queue worker ("php artisan queue:work") under Supervisor or your hosting panel.',
            ];
        }

        return $checks;
    }

    /**
     * Another add-on this one cannot run without: installed, at the version
     * asked for or newer, to install; and running, to enable.
     *
     * @return array{key: string, label: string, passed: bool, fix: string|null}
     */
    private function requiredAddon(string $slug, string $minimum, bool $mustRun): array
    {
        $name      = $this->addonName($slug);
        $installed = Addon::query()->where('slug', $slug)->first();
        $present   = $installed !== null && $installed->status !== AddonStatus::FAILED;
        $recent    = $present            && version_compare((string) $installed->version, $minimum, '>=');
        $running   = $recent             && $installed->isActive();
        $passed    = $mustRun ? $running : $recent;

        return [
            'key'    => 'addon:'.$slug,
            'label'  => $mustRun ? sprintf('%s add-on %s or later, running', $name, $minimum) : sprintf('%s add-on %s or later, installed', $name, $minimum),
            'passed' => $passed,
            'fix'    => match (true) {
                $passed    => null,
                ! $present => sprintf('Install the %s add-on (%s or later) first.', $name, $minimum),
                ! $recent  => sprintf('Update the %s add-on to %s or later first; %s is installed.', $name, $minimum, $installed->version),
                default    => sprintf('Enable the %s add-on first.', $name),
            },
        ];
    }

    /**
     * Another add-on this one works beside when it is there at all: absent is
     * fine, but an older one than this add-on was written for is not, since
     * the two would meet at runtime and one of them would break.
     *
     * @return array{key: string, label: string, passed: bool, fix: string|null}
     */
    private function compatibleAddon(string $slug, string $minimum): array
    {
        $name      = $this->addonName($slug);
        $installed = Addon::query()->where('slug', $slug)->first();
        $tooOld    = $installed !== null && $installed->status !== AddonStatus::FAILED && version_compare((string) $installed->version, $minimum, '<');

        return [
            'key'    => 'works-with:'.$slug,
            'label'  => sprintf('%s add-on %s or later, when installed', $name, $minimum),
            'passed' => ! $tooOld,
            'fix'    => $tooOld ? sprintf('Update the %s add-on to %s or later (%s is installed), or uninstall it: this add-on cannot work beside the older one.', $name, $minimum, $installed->version) : null,
        ];
    }

    private function addonName(string $slug): string
    {
        return $this->registry->find($slug)?->name ?? Addon::query()->where('slug', $slug)->value('name') ?? $slug;
    }

    /**
     * The fix text of every failed check; empty when the add-on can proceed.
     *
     * @return list<string>
     */
    public function failures(AddonManifest $manifest, bool $includeRuntime = true): array
    {
        $failures = [];

        foreach ($this->check($manifest, $includeRuntime) as $check) {
            if (! $check['passed']) {
                $failures[] = $check['label'].': '.$check['fix'];
            }
        }

        return $failures;
    }
}
