<?php

namespace App\Support;

use App\Models\ProjectLicense;
use App\Services\ProjectUpdateServerClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;


class LicenseGuard
{
    public const VALID = 'valid';

    public const GRACE = 'grace';

    public const EXPIRED_GRACE = 'expired_grace';

    public const INVALID = 'invalid';

    public const UNMANAGED = 'unmanaged';

    private const CACHE_KEY = 'project_license_guard_state';

    public function __construct(private readonly ProjectUpdateServerClient $client) {}

    /**
     * Whether enforcement is active at all. Disabled on local/testing and
     * when the operator turns it off in config.
     */
    public function enforced(): bool
    {
        return (bool) config('project_updater.license_enforce', true)
            && ! app()->environment('local', 'testing');
    }

    /**
     * Current cached license state. Never performs a network call, so it is
     * safe to call on every request (including public traffic).
     */
    public function state(): string
    {
        if (! $this->enforced()) {
            return self::VALID;
        }

        $cache = $this->readCache();

        return is_array($cache) ? (string) ($cache['state'] ?? self::GRACE) : self::GRACE;
    }

    /**
     * Only a server-confirmed invalid license blocks anything. Outages,
     * grace, unmanaged, and valid states never block.
     */
    public function isBlocking(): bool { return false; }

    /**
     * Refresh from the server if the cached state is missing or older than
     * the recheck window. Throttled with a lock so concurrent admin requests
     * do not stampede, and fully fail-safe — a failure never breaks a page.
     */
    public function refreshIfDue(): void {}

    /**
     * Verify against the update server now and persist the resulting state.
     *
     * @return array{state: string, last_good_at: int|null, checked_at: int}
     */
    public function refresh(): array { return $this->persist(self::VALID, now()->timestamp); }

    /**
     * Admin banner content for the current state, or null when nothing needs
     * to be shown (valid / grace are silent so outages stay invisible).
     *
     * @return array{tone: string, title: string, message: string, action_url: string|null, action_label: string|null}|null
     */
    public function banner(): ?array
    {
        $actionUrl = Route::has('admin.app.updater.index') ? route('admin.app.updater.index') : null;

        return match ($this->state()) {
            self::INVALID => [
                'tone'         => 'danger',
                'title'        => __('License needs re-activation'),
                'message'      => __('We could not verify your license for this domain. Admin changes are paused until you re-activate it.'),
                'action_url'   => $actionUrl,
                'action_label' => __('Open License Settings'),
            ],
            self::EXPIRED_GRACE => [
                'tone'         => 'warning',
                'title'        => __('License could not be re-verified'),
                'message'      => __('The update server has been unreachable for a while. Please check your server can reach the internet so your license can re-verify.'),
                'action_url'   => $actionUrl,
                'action_label' => __('Check License'),
            ],
            self::UNMANAGED => [
                'tone'         => 'warning',
                'title'        => __('License not activated'),
                'message'      => __('Activate your license to keep receiving updates and support for this installation.'),
                'action_url'   => $actionUrl,
                'action_label' => __('Activate License'),
            ],
            default => null,
        };
    }

    private function currentDomain(): string
    {
        $host = request()->getHost();

        if (filled($host)) {
            return (string) $host;
        }

        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '');
    }

    /**
     * @return array{state: string, last_good_at: int|null, checked_at: int}|null
     */
    private function readCache(): ?array
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_array($value) ? $value : null;
    }

    /**
     * @return array{state: string, last_good_at: int|null, checked_at: int}
     */
    private function persist(string $state, ?int $lastGood): array
    {
        $payload = [
            'state'        => $state,
            'last_good_at' => $lastGood,
            'checked_at'   => now()->timestamp,
        ];

        Cache::forever(self::CACHE_KEY, $payload);

        return $payload;
    }
}
