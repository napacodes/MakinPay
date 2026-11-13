<?php

declare(strict_types=1);

namespace App\Services\Addons;

use App\Enums\AddonStatus;
use App\Models\Addon;
use App\Models\ProjectLicense;
use App\Services\FeatureManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;

/**
 * What the Add-ons tab shows for each add-on, derived in one place.
 *
 * Every row is the union of the code on disk (AddonRegistry), the operator's
 * record (`addons`) and what is actually running (AddonLoader), so a card can
 * never claim an add-on is on when it is not. The rules are the backend's
 * own: an add-on counts as able to enable exactly when AddonManager::enable()
 * would accept it, and a missing license inside the grace period or a dead
 * cron leaves an active add-on running with a warning (decided 2026-10-06).
 *
 * Every row lands in exactly one overview bucket - active, attention,
 * disabled or absent - so the overview bar always adds up to the total.
 */
final class AddonCatalog
{
    public const STATE_BROKEN = 'broken';

    public const STATE_MISSING_FROM_DISK = 'missing_from_disk';

    public const STATE_FAILED = 'failed';

    public const STATE_INCOMPATIBLE = 'incompatible';

    public const STATE_NOT_INSTALLED = 'not_installed';

    public const STATE_ACTIVE = 'active';

    public const STATE_ACTIVE_WARNING = 'active_warning';

    public const STATE_HELD = 'held';

    public const STATE_INSTALLED = 'installed';

    public const STATE_DISABLED = 'disabled';

    public const STATE_DISABLED_BLOCKED = 'disabled_blocked';

    public const BUCKET_ACTIVE = 'active';

    public const BUCKET_ATTENTION = 'attention';

    public const BUCKET_DISABLED = 'disabled';

    public const BUCKET_ABSENT = 'absent';

    public function __construct(
        private readonly AddonRegistry $registry,
        private readonly AddonLoader $loader,
        private readonly AddonRequirements $requirements,
        private readonly AddonLicensing $licensing,
    ) {}

    /**
     * @return list<array<string, mixed>> sorted by name
     */
    public function rows(): array
    {
        $installed = Addon::query()->get()->keyBy('slug');
        $rows      = [];

        foreach ($this->registry->all() as $slug => $manifest) {
            $rows[$slug] = $this->row($manifest, $installed->get($slug), $slug);
        }

        foreach ($installed as $slug => $addon) {
            if (! isset($rows[$slug])) {
                $rows[$slug] = $this->row(null, $addon, (string) $slug);
            }
        }

        foreach ($this->registry->problems() as $folder => $problems) {
            if (! isset($rows[$folder])) {
                $rows[$folder] = $this->brokenRow((string) $folder, $problems);
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Which add-on owns each add-on feature in the Features grid, and whether
     * that feature is locked - its add-on is not running, so the feature is
     * off whatever its own switch says. The reason and the way out come from
     * the state this catalog already decided for the add-on's card.
     *
     * @param  list<array<string, mixed>>                                                                            $rows
     * @return array<string, array{slug: string, name: string, locked: bool, reason: string|null, cta: string|null}>
     */
    public function featureOwners(array $rows): array
    {
        $owners = [];

        foreach ($rows as $row) {
            $locked = ! in_array($row['state'], [self::STATE_ACTIVE, self::STATE_ACTIVE_WARNING], true);

            [$reason, $cta] = match (true) {
                ! $locked                               => [null, null],
                $row['state'] === self::STATE_DISABLED  => [__('Locked · the add-on is switched off'), __('Enable in Add-ons')],
                $row['state'] === self::STATE_INSTALLED => $row['blockers'] === []
                    ? [__('Locked · the add-on is not switched on yet'), __('Enable in Add-ons')]
                    : [__('Locked · :reason', ['reason' => $row['blockers'][0]]), __('Fix in Add-ons')],
                $row['state'] === self::STATE_FAILED => [__('Locked · the add-on\'s install failed'), __('Fix in Add-ons')],
                default                              => [__('Locked · :reason', ['reason' => (string) ($row['note']['text'] ?? __('the add-on is not running'))]), __('Fix in Add-ons')],
            };

            foreach ($row['features'] as $key) {
                $owners[$key] = ['slug' => $row['slug'], 'name' => $row['name'], 'locked' => $locked, 'reason' => $reason, 'cta' => $cta];
            }
        }

        return $owners;
    }

    /**
     * Totals for the tab, rail, filter chips and overview bar.
     *
     * @param  list<array<string, mixed>>                                                                                                  $rows
     * @return array{total: int, attention: int, buckets: array<string, int>, filters: array<string, int>, publishers: array<string, int>}
     */
    public function summary(array $rows): array
    {
        $buckets    = [self::BUCKET_ACTIVE => 0, self::BUCKET_ATTENTION => 0, self::BUCKET_DISABLED => 0, self::BUCKET_ABSENT => 0];
        $filters    = ['active' => 0, 'disabled' => 0, 'not_installed' => 0];
        $publishers = ['coevs' => 0, 'other' => 0];

        foreach ($rows as $row) {
            $buckets[$row['bucket']]++;
            $filters[$row['filter']]++;
            $publishers[$row['verified'] ? 'coevs' : 'other']++;
        }

        return [
            'total'      => count($rows),
            'attention'  => $buckets[self::BUCKET_ATTENTION],
            'buckets'    => $buckets,
            'filters'    => $filters,
            'publishers' => $publishers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?AddonManifest $manifest, ?Addon $addon, string $slug): array
    {
        $base = [
            'slug'          => $slug,
            'name'          => $manifest->name      ?? $addon?->name ?? $slug,
            'publisher'     => $manifest->publisher ?? __('Unknown publisher'),
            'verified'      => ($addon?->meta['signed_by'] ?? null) === 'coevs',
            'version'       => $addon?->version ?? $manifest?->version ?? '',
            'description'   => $manifest?->description,
            'features'      => $manifest?->featureKeys() ?? [],
            'menus'         => count($manifest?->adminMenus ?? []),
            'permissions'   => count($manifest?->permissions ?? []),
            'category'      => $this->firstCategory($manifest),
            'installed'     => $addon !== null,
            'installed_at'  => $addon?->installed_at,
            'enabled_at'    => $addon?->enabled_at,
            'last_error'    => $addon?->last_error,
            'pills'         => [],
            'note'          => null,
            'action'        => null,
            'switch'        => ['shown' => false, 'checked' => false, 'disabled' => true, 'reason' => null],
            'blockers'      => [],
            'requires_core' => $manifest?->requiresCore,
            'detail'        => $manifest !== null ? $this->detail($manifest) : null,
        ];

        if ($manifest === null) {
            return $this->state($base, self::STATE_MISSING_FROM_DISK, self::BUCKET_ATTENTION, 'disabled', [
                $this->tone('danger', 'fa-folder-minus', __('Files missing from addons/')),
            ], $this->tone('danger', 'fa-triangle-exclamation', __('The add-on folder was removed. Its features stay off until it is put back.')));
        }

        if ($addon === null) {
            if (! $this->registry->isCompatible($manifest)) {
                return $this->state($base, self::STATE_INCOMPATIBLE, self::BUCKET_ABSENT, 'not_installed', [
                    $this->tone('danger', 'fa-ban', __('Requires DigiKash :version+ · you have :current', ['version' => $manifest->requiresCore, 'current' => $this->registry->coreVersion()])),
                ], $this->tone('danger', 'fa-ban', __('Update DigiKash to :version or later first.', ['version' => $manifest->requiresCore])), $this->action('install', __('Install'), disabled: true));
            }

            return $this->state($base, self::STATE_NOT_INSTALLED, self::BUCKET_ABSENT, 'not_installed', [
                $this->tone('neutral', 'fa-circle-down', __('Not installed')),
            ], null, $this->action('install', __('Install')));
        }

        if ($addon->status === AddonStatus::FAILED) {
            return $this->state($base, self::STATE_FAILED, self::BUCKET_ATTENTION, 'not_installed', [
                $this->tone('danger', 'fa-circle-xmark', __('Install failed · rolled back')),
            ], $this->tone('danger', 'fa-circle-xmark', (string) ($addon->last_error ?: __('The last install did not finish.'))), $this->action('install', __('Retry install')));
        }

        $installedAt     = $addon->installed_at;
        $licenseBlocker  = $this->licensing->blocker($manifest, $installedAt);
        $runtimeFailures = $this->requirements->failures($manifest);
        $enableBlockers  = array_values(array_filter([...$runtimeFailures, $licenseBlocker]));
        $licensePill     = $this->licensePill($this->licensing->state($manifest), $installedAt);

        $base['blockers'] = $enableBlockers;

        if ($addon->status === AddonStatus::ACTIVE) {
            if (! $this->loader->isLoaded($manifest->slug)) {
                $reason = $this->loader->licenseBlockers()[$manifest->slug]
                    ?? ($this->loader->failures()[$manifest->slug][0] ?? null)
                    ?? ($this->loader->blockers($manifest)[0] ?? __('It is switched on but not running.'));

                return $this->state($base, self::STATE_HELD, self::BUCKET_ATTENTION, 'disabled', array_values(array_filter([
                    $this->tone('warning', 'fa-circle-pause', __('On · not running')),
                    $licensePill,
                ])), $this->tone('danger', 'fa-triangle-exclamation', $reason), $this->action('open', __('Open')), $this->switch(true, true, $reason));
            }

            $warnings = array_values(array_filter([
                $licensePill,
                $runtimeFailures !== [] ? $this->tone('warning', 'fa-wrench', trans_choice('Requirements missing · :count', count($runtimeFailures), ['count' => count($runtimeFailures)])) : null,
            ]));

            $note = $runtimeFailures !== []
                ? $this->tone('warning', 'fa-wrench', $runtimeFailures[0])
                : $this->tone('success', 'fa-circle-check', $addon->enabled_at !== null ? __('On since :when', ['when' => $addon->enabled_at->diffForHumans()]) : __('Running'));

            return $this->state(
                $base,
                $warnings === [] ? self::STATE_ACTIVE : self::STATE_ACTIVE_WARNING,
                $warnings === [] ? self::BUCKET_ACTIVE : self::BUCKET_ATTENTION,
                'active',
                [$this->tone('success', 'fa-circle-check', __('Installed · active')), ...$warnings],
                $note,
                $this->action('open', __('Open')),
                $this->switch(true, false, null),
            );
        }

        $canEnable = $enableBlockers === [];
        $blocker   = $enableBlockers[0] ?? null;

        if ($addon->status === AddonStatus::INSTALLED) {
            return $this->state(
                $base,
                self::STATE_INSTALLED,
                self::BUCKET_ATTENTION,
                'disabled',
                array_values(array_filter([$this->tone('neutral', 'fa-circle-pause', __('Installed · not enabled yet')), $licensePill])),
                $canEnable ? $this->tone('neutral', 'fa-circle-info', __('Configure it, then switch it on.')) : $this->tone('warning', 'fa-wrench', (string) $blocker),
                $canEnable ? $this->action('open', __('Open')) : $this->action('fix', __('Fix requirements')),
                $this->switch(false, ! $canEnable, $blocker),
            );
        }

        return $this->state(
            $base,
            $canEnable ? self::STATE_DISABLED : self::STATE_DISABLED_BLOCKED,
            $canEnable ? self::BUCKET_DISABLED : self::BUCKET_ATTENTION,
            'disabled',
            array_values(array_filter([
                $this->tone('neutral', 'fa-circle-pause', __('Installed · disabled')),
                $canEnable ? null : $this->tone('warning', 'fa-wrench', trans_choice('Requirements missing · :count', count($enableBlockers), ['count' => count($enableBlockers)])),
                $licensePill,
            ])),
            $canEnable
                ? $this->tone('neutral', 'fa-circle-pause', __('Turned off :when', ['when' => $addon->updated_at?->diffForHumans() ?? '']))
                : $this->tone('warning', 'fa-wrench', (string) $blocker),
            $canEnable ? $this->action('open', __('Open')) : $this->action('fix', __('Fix requirements')),
            $this->switch(false, ! $canEnable, $blocker),
        );
    }

    /**
     * @param  list<string>         $problems
     * @return array<string, mixed>
     */
    private function brokenRow(string $folder, array $problems): array
    {
        return [
            'slug'          => $folder,
            'name'          => $folder,
            'publisher'     => __('Unknown publisher'),
            'verified'      => false,
            'version'       => '',
            'description'   => null,
            'features'      => [],
            'menus'         => 0,
            'permissions'   => 0,
            'category'      => null,
            'installed'     => false,
            'installed_at'  => null,
            'enabled_at'    => null,
            'last_error'    => null,
            'state'         => self::STATE_BROKEN,
            'bucket'        => self::BUCKET_ABSENT,
            'filter'        => 'not_installed',
            'pills'         => [$this->tone('danger', 'fa-triangle-exclamation', __('Package unreadable'))],
            'note'          => $this->tone('danger', 'fa-triangle-exclamation', $problems[0] ?? __('The add-on folder could not be read.')),
            'action'        => null,
            'switch'        => ['shown' => false, 'checked' => false, 'disabled' => true, 'reason' => null],
            'blockers'      => $problems,
            'requires_core' => null,
            'detail'        => null,
        ];
    }

    /**
     * @param  array<string, mixed>                                                        $row
     * @param  list<array{tone: string, icon: string, text: string}>                       $pills
     * @param  array{tone: string, icon: string, text: string}|null                        $note
     * @param  array{kind: string, label: string, disabled: bool}|null                     $action
     * @param  array{shown: bool, checked: bool, disabled: bool, reason: string|null}|null $switch
     * @return array<string, mixed>
     */
    private function state(array $row, string $state, string $bucket, string $filter, array $pills, ?array $note, ?array $action = null, ?array $switch = null): array
    {
        return array_merge($row, [
            'state'  => $state,
            'bucket' => $bucket,
            'filter' => $filter,
            'pills'  => $pills,
            'note'   => $note,
            'action' => $action,
            'switch' => $switch ?? $row['switch'],
        ]);
    }

    /**
     * @return array{tone: string, icon: string, text: string}|null
     */
    private function licensePill(string $state, ?CarbonInterface $installedAt): ?array
    {
        return match ($state) {
            AddonLicensing::MISSING => $this->tone('warning', 'fa-key', $this->graceText($installedAt)),
            AddonLicensing::INVALID => $this->tone('danger', 'fa-key', __('License rejected')),
            default                 => null,
        };
    }

    private function graceText(?CarbonInterface $installedAt): string
    {
        if ($installedAt === null) {
            return __('License missing');
        }

        $daysLeft = (int) ceil(now()->diffInDays($installedAt->copy()->addDays($this->licensing->graceDays()), false));

        return $daysLeft > 0
            ? trans_choice('License missing · :count day left|License missing · :count days left', $daysLeft, ['count' => $daysLeft])
            : __('License missing · grace period over');
    }

    /**
     * A pill or a footer note: a tone, a Font Awesome icon and its text.
     *
     * @return array{tone: string, icon: string, text: string}
     */
    private function tone(string $tone, string $icon, string $text): array
    {
        return ['tone' => $tone, 'icon' => $icon, 'text' => $text];
    }

    /**
     * @return array{kind: string, label: string, disabled: bool}
     */
    private function action(string $kind, string $label, bool $disabled = false): array
    {
        return ['kind' => $kind, 'label' => $label, 'disabled' => $disabled];
    }

    /**
     * @return array{shown: bool, checked: bool, disabled: bool, reason: string|null}
     */
    private function switch(bool $checked, bool $disabled, ?string $reason): array
    {
        return ['shown' => true, 'checked' => $checked, 'disabled' => $disabled, 'reason' => $reason];
    }

    /**
     * What the detail drawer shows beyond the card: what the add-on adds,
     * every requirement with its fix, and its license as stored - the
     * purchase code only ever as its last four characters.
     *
     * @return array<string, mixed>
     */
    private function detail(AddonManifest $manifest): array
    {
        $features = app(FeatureManager::class);
        $license  = ProjectLicense::query()->where('product_slug', $manifest->slug)->latest('id')->first();
        $code     = $license?->safePurchaseCode();

        return [
            'features' => collect($manifest->features)
                ->map(fn (array $feature, string $key): array => [
                    'key'      => $key,
                    'label'    => (string) ($feature['label'] ?? $key),
                    'category' => (string) ($feature['category'] ?? ''),
                    'on'       => $features->isEnabled($key),
                ])
                ->values()
                ->all(),
            'menus'        => array_values(array_filter(array_map(fn (array $menu): ?string => is_string($menu['label'] ?? null) ? $menu['label'] : null, $manifest->adminMenus))),
            'permissions'  => $manifest->permissions,
            'requirements' => $this->requirements->check($manifest),
            'license'      => [
                'required'        => $manifest->itemId !== null,
                'state'           => $this->licensing->state($manifest),
                'domain'          => $license?->domain,
                'support_until'   => $license?->support_until,
                'last_checked_at' => $license?->last_checked_at,
                'masked_code'     => $code !== null ? '••••••••-'.substr($code, -4) : null,
            ],
            'settings_url' => $this->settingsUrl($manifest),
            'migrations'   => count(glob($manifest->migrationsPath().'/*.php') ?: []),
        ];
    }

    /**
     * The add-on's first admin menu entry, when its route is registered -
     * the nearest thing to a settings page a manifest declares.
     */
    private function settingsUrl(AddonManifest $manifest): ?string
    {
        foreach ($manifest->adminMenus as $menu) {
            $name = $menu['route'] ?? null;

            if (is_string($name) && Route::has($name)) {
                return route($name);
            }
        }

        return null;
    }

    private function firstCategory(?AddonManifest $manifest): ?string
    {
        foreach ($manifest?->features ?? [] as $feature) {
            if (is_string($feature['category'] ?? null)) {
                return $feature['category'];
            }
        }

        return null;
    }
}
