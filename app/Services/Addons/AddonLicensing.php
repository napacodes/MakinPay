<?php

declare(strict_types=1);

namespace App\Services\Addons;

use App\Models\ProjectLicense;
use App\Services\ProjectUpdaterService;
use App\Services\ProjectUpdateServerClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A paid add-on's license: its own item id, its own purchase code, held
 * in project_licenses under the add-on's slug and checked by the same update
 * server as core.
 *
 * Enforcement is soft. Nothing is checked where registration is off (local,
 * testing, demo, or switched off in config). A missing license is tolerated
 * for the grace period after install; past it, and the moment the server
 * confirms a code invalid, the add-on stops loading and its features read as
 * off. Its data and the admin's settings stay.
 *
 * This is INDEPENDENT of the core product license that Envato required: the
 * core guard was removed in Milestone 1, but add-on licensing is a separate
 * mechanism and stays. The enablement rule below is therefore self-contained
 * rather than delegated to a core license service.
 */
final class AddonLicensing
{
    public const NOT_REQUIRED = 'not_required';

    public const VALID = 'valid';

    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    public function __construct(
        private readonly ProjectUpdateServerClient $client,
        private readonly ?bool $enforced = null,
    ) {}

    /**
     * Whether add-on licensing is enforced at all.
     *
     * Off in local/testing (so developers never need purchase codes), off in
     * demo deployments, and off when the operator disables it. Otherwise on.
     */
    public function enforced(): bool
    {
        if ($this->enforced !== null) {
            return $this->enforced;
        }

        if ((bool) config('app.demo', false)) {
            return false;
        }

        return (bool) config('project_updater.addon_license_enforce', true)
            && ! app()->environment('local', 'testing');
    }

    public function requiresLicense(AddonManifest $manifest): bool
    {
        return $manifest->itemId !== null && $this->enforced();
    }

    public function state(AddonManifest $manifest): string
    {
        if (! $this->requiresLicense($manifest)) {
            return self::NOT_REQUIRED;
        }

        $license = $this->licenseRow($manifest->slug);

        if ($license === null || blank($license->license_token ?? null)) {
            return self::MISSING;
        }

        return ($license->status ?? null) === 'invalid' ? self::INVALID : self::VALID;
    }

    /**
     * May this add-on run? A missing license is tolerated until the grace
     * period after install runs out; an invalid one never is.
     */
    public function isUsable(AddonManifest $manifest, ?CarbonInterface $installedAt): bool
    {
        return match ($this->state($manifest)) {
            self::NOT_REQUIRED, self::VALID => true,
            self::MISSING => $installedAt !== null && $installedAt->copy()->addDays($this->graceDays())->isFuture(),
            default       => false,
        };
    }

    /**
     * The reason an add-on may not run, for the operator; null when it may.
     */
    public function blocker(AddonManifest $manifest, ?CarbonInterface $installedAt): ?string
    {
        if ($this->isUsable($manifest, $installedAt)) {
            return null;
        }

        return $this->state($manifest) === self::INVALID
            ? 'The license server rejected this add-on\'s purchase code. Activate a valid code.'
            : sprintf('No license is active and the %d-day grace period has ended. Activate the add-on\'s purchase code.', $this->graceDays());
    }

    public function activate(AddonManifest $manifest, string $purchaseCode): ProjectLicense
    {
        $data    = $this->client->activate($purchaseCode, null, $manifest->slug, (string) $manifest->itemId);
        $license = $data['license'] ?? $data;

        if (! is_array($license)) {
            throw AddonOperationFailed::because('The license server sent an activation response this site could not read.');
        }

        return app(ProjectUpdaterService::class)->storeActivation($manifest->slug, (string) $manifest->itemId, $purchaseCode, $license);
    }

    /**
     * Re-verifies the stored code. Only a confirmed rejection (401/403)
     * marks it invalid; an unreachable server changes nothing.
     */
    public function refresh(AddonManifest $manifest): string
    {
        if (! $this->requiresLicense($manifest)) {
            return self::NOT_REQUIRED;
        }

        $license = ProjectLicense::query()->where('product_slug', $manifest->slug)->latest('id')->first();

        if (! $license instanceof ProjectLicense || blank($license->license_token)) {
            return self::MISSING;
        }

        $result = $this->client->verifyLicense((string) $license->license_token, (string) $license->domain, $manifest->slug, (string) $manifest->itemId);

        if ($result['outcome'] === 'valid') {
            $license->forceFill(['status' => 'active', 'last_checked_at' => now()])->saveQuietly();
        } elseif ($result['outcome'] === 'invalid') {
            $license->forceFill(['status' => 'invalid', 'last_checked_at' => now()])->saveQuietly();
        }

        return $this->state($manifest);
    }

    public function graceDays(): int
    {
        return max(0, (int) config('project_updater.license_grace_days', 14));
    }

    /**
     * Read through the query builder: the loader asks during the register
     * phase, before Eloquent's connection resolver exists.
     */
    private function licenseRow(string $slug): ?object
    {
        try {
            return app('db')->table('project_licenses')
                ->select(['license_token', 'status'])
                ->where('product_slug', $slug)
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @internal for callers holding a raw timestamp column value
     */
    public static function parseTimestamp(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
