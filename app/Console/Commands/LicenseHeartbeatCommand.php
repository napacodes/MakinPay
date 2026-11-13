<?php

namespace App\Console\Commands;

use App\Services\Addons\AddonLicensing;
use App\Services\Addons\AddonLoader;
use App\Support\LicenseGuard;
use Illuminate\Console\Command;
use Throwable;

class LicenseHeartbeatCommand extends Command
{
    protected $signature = 'license:heartbeat';

    protected $description = 'Re-verify the project license against the update server and refresh the cached state.';

    public function handle(LicenseGuard $guard, AddonLoader $addons, AddonLicensing $licensing): int
    {
        if (! $guard->enforced()) {
            $this->components->info('License enforcement is disabled — nothing to verify.');

            return self::SUCCESS;
        }

        $result = $guard->refresh();

        $this->components->info(sprintf('License state: %s', $result['state']));

        foreach (array_keys($addons->installed()) as $slug) {
            $manifest = $addons->registry()->find($slug);

            if ($manifest === null || ! $licensing->requiresLicense($manifest)) {
                continue;
            }

            try {
                $this->components->info(sprintf('Add-on %s license: %s', $slug, $licensing->refresh($manifest)));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return self::SUCCESS;
    }
}
