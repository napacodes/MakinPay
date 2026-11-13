<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Enums\AddonStatus;
use App\Exceptions\NotifyErrorException;
use App\Http\Requests\Backend\ActivateProjectLicenseRequest;
use App\Http\Requests\Backend\ConfirmAddonOperationRequest;
use App\Http\Requests\Backend\InstallAddonPackageRequest;
use App\Http\Requests\Backend\UninstallAddonRequest;
use App\Http\Requests\Backend\UpdateAddonStatusRequest;
use App\Http\Requests\Backend\UploadAddonPackageRequest;
use App\Models\Addon;
use App\Models\Feature;
use App\Services\Addons\AddonLicensing;
use App\Services\Addons\AddonManager;
use App\Services\Addons\AddonManifest;
use App\Services\Addons\AddonOperationFailed;
use App\Services\Addons\AddonPreflight;
use App\Services\Addons\AddonRegistry;
use App\Services\FeatureManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Add-ons tab of Feature Management.
 *
 * Every write goes through AddonManager, so the requirement, license and lock
 * rules are the backend's and never re-implemented here. A refusal comes back
 * to the page with every reason the manager gave. Anything that runs a
 * package's code or drops its tables asks for the admin's password (and 2FA
 * code) first.
 */
class AddonController extends BaseController
{
    private const UPLOAD_DISK = 'local';

    private const UPLOAD_TTL_SECONDS = 86400;

    public function __construct(private readonly AddonManager $addons) {}

    public static function permissions(): array
    {
        return [
            'status'         => 'addon-manage',
            'license'        => 'addon-manage',
            'refreshLicense' => 'addon-manage',
            'uploadPackage'  => 'addon-manage',
            'recheckPackage' => 'addon-manage',
            'discardPackage' => 'addon-manage',
            'installPackage' => 'addon-manage',
            'install'        => 'addon-manage',
            'uninstall'      => 'addon-manage',
            'features'       => 'feature-manage',
        ];
    }

    /**
     * Installer steps 1-4: take the package, verify it and report what
     * installing it would do. Nothing is installed; the upload is kept for
     * this admin's session under a one-use token - unless it would claim a
     * feature key someone else owns, when no token is issued at all.
     */
    public function uploadPackage(UploadAddonPackageRequest $request, AddonPreflight $preflight): JsonResponse
    {
        $this->pruneAbandonedUploads();

        $token = Str::random(40);
        $path  = $request->file('package')->storeAs('addon-uploads', $token.'.zip', self::UPLOAD_DISK);

        try {
            $report = $preflight->inspect(Storage::disk(self::UPLOAD_DISK)->path($path));
        } catch (AddonOperationFailed $failed) {
            Storage::disk(self::UPLOAD_DISK)->delete($path);

            return response()->json(['ok' => false, 'reasons' => $failed->reasons], 422);
        }

        if ($report['conflicts'] !== []) {
            Storage::disk(self::UPLOAD_DISK)->delete($path);

            return response()->json(['ok' => true, 'token' => null, 'report' => $report]);
        }

        $request->session()->put('addon_uploads.'.$token, ['path' => $path, 'slug' => $report['slug']]);

        return response()->json(['ok' => true, 'token' => $token, 'report' => $report]);
    }

    /**
     * The installer's Re-check: the same report for the upload this session
     * already holds, after the admin fixed something on the server.
     */
    public function recheckPackage(Request $request, string $token, AddonPreflight $preflight): JsonResponse
    {
        $upload = $this->uploadFor($request, $token);

        if ($upload === null) {
            return $this->uploadExpired();
        }

        try {
            $report = $preflight->inspect(Storage::disk(self::UPLOAD_DISK)->path($upload['path']));
        } catch (AddonOperationFailed $failed) {
            return response()->json(['ok' => false, 'reasons' => $failed->reasons], 422);
        }

        return response()->json(['ok' => true, 'token' => $token, 'report' => $report]);
    }

    /**
     * Cancel install: the upload is deleted and its token spent.
     */
    public function discardPackage(Request $request, string $token): JsonResponse
    {
        $upload = $request->session()->pull('addon_uploads.'.$token);

        if (is_array($upload) && is_string($upload['path'] ?? null)) {
            Storage::disk(self::UPLOAD_DISK)->delete($upload['path']);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Installer step 5: install (or update from) the verified upload, then
     * activate the License step's code if one was given. The token is spent
     * whatever happens, and the upload deleted.
     */
    public function installPackage(InstallAddonPackageRequest $request, string $token, AddonRegistry $registry, AddonLicensing $licensing): JsonResponse
    {
        $upload = $this->uploadFor($request, $token);
        $request->session()->forget('addon_uploads.'.$token);

        if ($upload === null) {
            return $this->uploadExpired();
        }

        ignore_user_abort(true);
        @set_time_limit(0);

        try {
            $addon = $this->addons->installPackage(Storage::disk(self::UPLOAD_DISK)->path($upload['path']));
        } catch (AddonOperationFailed $failed) {
            $attempt = Addon::query()->where('slug', $upload['slug'])->first();

            return response()->json([
                'ok'      => false,
                'reasons' => $failed->reasons,
                'failed'  => $attempt?->status === AddonStatus::FAILED,
            ], 422);
        } finally {
            Storage::disk(self::UPLOAD_DISK)->delete($upload['path']);
        }

        return response()->json([
            'ok'      => true,
            'slug'    => $addon->slug,
            'name'    => $addon->name,
            'version' => $addon->version,
            'status'  => $addon->status->value,
            'license' => $this->activateWithInstall($registry->find($addon->slug), $licensing, $request->validated('purchase_code')),
        ]);
    }

    /**
     * Installs an add-on already in addons/<slug>, or retries a failed one.
     */
    public function install(ConfirmAddonOperationRequest $request, string $slug): RedirectResponse
    {
        try {
            $addon = $this->addons->install($slug);
        } catch (AddonOperationFailed $failed) {
            return $this->backToAddons($slug)->with('notifyevs', ['type' => 'warning', 'message' => implode(' ', $failed->reasons)]);
        }

        return $this->backToAddons($slug)->with('notifyevs', [
            'type'    => 'success',
            'message' => __('":name" is installed. Switch it on when it is configured.', ['name' => $addon->name]),
        ]);
    }

    public function uninstall(UninstallAddonRequest $request, string $slug): RedirectResponse
    {
        $keepData = (bool) $request->validated('keep_data');

        try {
            $this->addons->uninstall($slug, $keepData);
        } catch (AddonOperationFailed $failed) {
            return $this->backToAddons($slug)->with('notifyevs', ['type' => 'warning', 'message' => implode(' ', $failed->reasons)]);
        }

        return $this->backToAddons()->with('notifyevs', [
            'type'    => 'success',
            'message' => $keepData
                ? __('The add-on is uninstalled. Its data is kept for a reinstall.')
                : __('The add-on is uninstalled and its data deleted.'),
        ]);
    }

    /**
     * Installer step 6: turn on the features of an add-on the admin just
     * installed. Only that add-on's own keys are touched.
     */
    public function features(string $slug, AddonRegistry $registry, FeatureManager $features): JsonResponse
    {
        $manifest = $registry->find($slug) ?? abort(404);
        abort_unless(Addon::query()->where('slug', $slug)->exists(), 404);

        Feature::query()->whereIn('key', $manifest->featureKeys())->update(['is_enabled' => true]);
        $features->flush();

        return response()->json(['ok' => true, 'features' => $manifest->featureKeys()]);
    }

    /**
     * Activates the add-on's purchase code, or replaces it.
     */
    public function license(ActivateProjectLicenseRequest $request, string $slug, AddonRegistry $registry, AddonLicensing $licensing): RedirectResponse
    {
        $manifest = $registry->find($slug) ?? abort(404);

        if ($manifest->itemId === null) {
            return $this->backToAddons($slug)->with('notifyevs', ['type' => 'info', 'message' => __('This add-on needs no license.')]);
        }

        try {
            $licensing->activate($manifest, (string) $request->validated('purchase_code'));
        } catch (AddonOperationFailed $failed) {
            return $this->backToAddons($slug)->with('notifyevs', ['type' => 'warning', 'message' => implode(' ', $failed->reasons)]);
        } catch (NotifyErrorException $refused) {
            return $this->backToAddons($slug)->with('notifyevs', ['type' => 'warning', 'message' => $refused->getMessage()]);
        }

        return $this->backToAddons($slug)->with('notifyevs', [
            'type'    => 'success',
            'message' => __('":name" is licensed to :domain.', ['name' => $manifest->name, 'domain' => request()->getHost()]),
        ]);
    }

    /**
     * Asks the license server about the stored code again.
     */
    public function refreshLicense(string $slug, AddonRegistry $registry, AddonLicensing $licensing): RedirectResponse
    {
        $manifest = $registry->find($slug) ?? abort(404);
        $state    = $licensing->refresh($manifest);

        return $this->backToAddons($slug)->with('notifyevs', match ($state) {
            AddonLicensing::VALID   => ['type' => 'success', 'message' => __('The license server confirmed this add-on\'s license.')],
            AddonLicensing::INVALID => ['type' => 'warning', 'message' => __('The license server rejected this add-on\'s purchase code. Activate a valid code.')],
            AddonLicensing::MISSING => ['type' => 'warning', 'message' => __('No purchase code is stored for this add-on yet.')],
            default                 => ['type' => 'info', 'message' => __('This add-on needs no license.')],
        });
    }

    /**
     * Switches an add-on on or off. The card and drawer post a form; the
     * installer's last step asks for JSON and shows the reasons itself.
     */
    public function status(UpdateAddonStatusRequest $request, string $slug): RedirectResponse|JsonResponse
    {
        $enable = (bool) $request->validated('is_enabled');

        try {
            $addon = $enable ? $this->addons->enable($slug) : $this->addons->disable($slug);
        } catch (AddonOperationFailed $failed) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'reasons' => $failed->reasons], 422);
            }

            return $this->backToAddons()->with('notifyevs', [
                'type'    => 'warning',
                'message' => implode(' ', $failed->reasons),
            ]);
        }

        $this->recordStatusChange($addon, (int) $request->user('admin')?->getAuthIdentifier());

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $addon->status->value]);
        }

        return $this->backToAddons()->with('notifyevs', [
            'type'    => 'success',
            'message' => $enable
                ? __('":name" is on. Turn its features on in the Features tab when it is ready for customers.', ['name' => $addon->name])
                : __('":name" is off. Its features are hidden in every panel; settings and data are kept.', ['name' => $addon->name]),
        ]);
    }

    /**
     * The License step's code, activated now that the add-on is on disk. A
     * refusal does not undo the install: the add-on stays installed and its
     * drawer offers activation again.
     *
     * @return array{activated: bool, message: string|null}
     */
    private function activateWithInstall(?AddonManifest $manifest, AddonLicensing $licensing, ?string $code): array
    {
        if ($manifest === null || blank($code) || ! $licensing->requiresLicense($manifest)) {
            return ['activated' => false, 'message' => null];
        }

        try {
            $licensing->activate($manifest, (string) $code);
        } catch (AddonOperationFailed $failed) {
            return ['activated' => false, 'message' => implode(' ', $failed->reasons)];
        } catch (NotifyErrorException $refused) {
            return ['activated' => false, 'message' => $refused->getMessage()];
        } catch (Throwable) {
            return ['activated' => false, 'message' => __('The license server could not be reached. Activate the code from the add-on\'s details later.')];
        }

        return ['activated' => true, 'message' => null];
    }

    /**
     * The stored upload behind a token this session was issued, or null.
     *
     * @return array{path: string, slug: string}|null
     */
    private function uploadFor(Request $request, string $token): ?array
    {
        $upload = $request->session()->get('addon_uploads.'.$token);

        if (! is_array($upload) || ! is_string($upload['path'] ?? null) || ! Storage::disk(self::UPLOAD_DISK)->exists($upload['path'])) {
            return null;
        }

        return ['path' => $upload['path'], 'slug' => (string) ($upload['slug'] ?? '')];
    }

    private function uploadExpired(): JsonResponse
    {
        return response()->json(['ok' => false, 'reasons' => [__('This upload has expired. Upload the package again.')]], 404);
    }

    /**
     * Uploads whose installer was closed without Cancel are deleted a day
     * later, the next time anyone uploads.
     */
    private function pruneAbandonedUploads(): void
    {
        $disk = Storage::disk(self::UPLOAD_DISK);

        foreach ($disk->files('addon-uploads') as $file) {
            if ($disk->lastModified($file) < now()->getTimestamp() - self::UPLOAD_TTL_SECONDS) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Who last switched it and when, for the card's "Turned off by …" note.
     */
    private function recordStatusChange(Addon $addon, int $adminId): void
    {
        $addon->forceFill([
            'meta' => array_replace((array) $addon->meta, [
                'status_changed_by' => $adminId ?: null,
                'status_changed_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    /**
     * Back to the Add-ons tab; with a slug, with that add-on's drawer open.
     */
    private function backToAddons(?string $slug = null): RedirectResponse
    {
        return redirect()->route('admin.features.index', array_filter(['tab' => 'addons', 'addon' => $slug]));
    }
}
