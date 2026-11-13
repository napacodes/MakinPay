<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\InstallationManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps Laravel's own maintenance state in step with the `maintenance_mode`
 * and `secret_key` site settings.
 *
 * The recovery URL is the only way back into a site the administrator has
 * taken down, so every path that can put the application down goes through
 * here: the key that is stored in the "down" payload is always the key the
 * settings screen is showing, and it is always URL-safe.
 */
class MaintenanceModeService
{
    /**
     * The view prerendered into the "down" payload so the maintenance screen
     * can be served without booting the framework.
     */
    public const VIEW = 'errors.maintenance';

    /**
     * Characters a secret key may contain. Anything else either breaks the
     * path comparison (`/`, whitespace) or is re-encoded by the browser
     * (`%`, `!`, `#`) so the URL no longer matches the stored key.
     */
    public const SECRET_PATTERN = '/[^A-Za-z0-9._~-]/';

    public function __construct(protected Application $app) {}

    /**
     * Reconcile the application's maintenance state with the saved settings.
     */
    public function sync(): void
    {
        if (! $this->settingsAvailable()) {
            return;
        }

        // Resolve first, so a legacy key holding characters that cannot
        // survive a URL is rewritten before the settings screen renders it as
        // the recovery URL.
        $secret = $this->secret();
        $isDown = $this->app->isDownForMaintenance();

        if (! $this->enabled()) {
            if ($isDown) {
                $this->deactivate();
            }

            return;
        }

        // Already down, but possibly with a stale payload: the key may have
        // been edited from the settings screen while the site was down, or
        // another process (an update install, a deploy script) may have run
        // a plain `artisan down` that left no recovery key at all. Either way
        // the URL the administrator was given no longer works, so re-apply.
        if (! $isDown || $this->activeSecret() !== $secret) {
            $this->activate($secret);
        }
    }

    /**
     * Put the application down with the current recovery key in place.
     */
    public function activate(?string $secret = null): void
    {
        $options = ['--secret' => $secret ?? $this->secret()];

        // Only prerender when the view resolves, so a broken or missing
        // maintenance view can never stop the site from going down.
        if ($this->app->make('view')->exists(self::VIEW)) {
            $options['--render'] = self::VIEW;
        }

        try {
            Artisan::call('down', $options);
        } catch (Throwable $e) {
            Log::error('Failed to enable maintenance mode: '.$e->getMessage());

            return;
        }

        $this->installBypassGuard();
    }

    /**
     * Bring the application back up.
     */
    public function deactivate(): void
    {
        try {
            Artisan::call('up');
        } catch (Throwable $e) {
            Log::error('Failed to disable maintenance mode: '.$e->getMessage());
        }
    }

    /**
     * Whether the administrator has switched maintenance mode on.
     */
    public function enabled(): bool
    {
        return (bool) setting('maintenance_mode', false);
    }

    /**
     * The URL-safe recovery key, persisting the normalised value so the
     * settings screen always displays exactly what the URL has to contain.
     */
    public function secret(): string
    {
        $stored = (string) setting('secret_key', '');
        $secret = $this->sanitize($stored);

        if ($secret === '') {
            // Never take the site down without a way back in.
            $secret = Str::lower(Str::random(24));
        }

        if ($secret !== $stored) {
            Setting::add('secret_key', $secret, 'string');
        }

        return $secret;
    }

    /**
     * The recovery key currently stored in the "down" payload, if any.
     */
    public function activeSecret(): ?string
    {
        if (! $this->app->isDownForMaintenance()) {
            return null;
        }

        try {
            $data = $this->app->maintenanceMode()->data();
        } catch (Throwable) {
            return null;
        }

        $secret = $data['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Strip everything that would not survive a round trip through a URL.
     */
    public function sanitize(mixed $secret): string
    {
        return trim((string) preg_replace(self::SECRET_PATTERN, '', trim((string) $secret)), '.-');
    }

    /**
     * Replace the framework's prerendered gate with the hardened one, which
     * tolerates trailing slashes, query strings and sub-directory installs
     * when matching the recovery key.
     *
     * A failure here is not fatal: the framework's own stub stays in place
     * and keeps working for the common "site at the domain root" layout.
     */
    protected function installBypassGuard(): void
    {
        $stub   = resource_path('stubs/maintenance-mode.php');
        $target = storage_path('framework/maintenance.php');

        if (! is_file($stub) || ! is_file($target)) {
            return;
        }

        if (@copy($stub, $target) === false) {
            Log::warning('Could not install the maintenance bypass guard at '.$target.'; the recovery URL will only work at the site root.');
        }
    }

    /**
     * Settings live in the database, which is not there yet during install.
     */
    protected function settingsAvailable(): bool
    {
        try {
            return $this->app->make(InstallationManager::class)->settingsTableAvailable();
        } catch (Throwable) {
            return false;
        }
    }
}
