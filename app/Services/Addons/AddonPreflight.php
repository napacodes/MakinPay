<?php

declare(strict_types=1);

namespace App\Services\Addons;

use App\Models\Addon;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * What installing an uploaded package would do, worked out before anything is
 * installed: the installer's Verify, Requirements and License steps.
 *
 * The package is staged exactly as AddonManager::installPackage() will stage
 * it - signature, entry paths, manifest - and the staging folder is deleted
 * before this returns. Nothing about this install changes.
 */
final class AddonPreflight
{
    public function __construct(
        private readonly AddonRegistry $registry,
        private readonly AddonRequirements $requirements,
        private readonly AddonLicensing $licensing,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AddonOperationFailed when the package itself cannot be trusted
     */
    public function inspect(string $zipPath): array
    {
        $staged = (new AddonPackage)->stage($zipPath, storage_path('app/addon-packages'));

        try {
            $manifest  = $staged['manifest'];
            $installed = Addon::query()->where('slug', $manifest->slug)->first();

            return [
                'slug'          => $manifest->slug,
                'name'          => $manifest->name,
                'publisher'     => $manifest->publisher,
                'version'       => $manifest->version,
                'description'   => $manifest->description,
                'requires_core' => $manifest->requiresCore,
                'core_version'  => $this->registry->coreVersion(),
                'compatible'    => $this->registry->isCompatible($manifest),
                'size'          => (int) filesize($zipPath),
                'files'         => $this->fileCount($zipPath),
                'fingerprint'   => $this->keyFingerprint(),
                'features'      => count($manifest->features),
                'menus'         => count($manifest->adminMenus),
                'permissions'   => count($manifest->permissions),
                'migrations'    => count(File::glob($staged['path'].'/database/migrations/*.php')),
                'requirements'  => $this->requirements->check($manifest),
                'license'       => [
                    'required' => $this->licensing->requiresLicense($manifest),
                    'state'    => $this->licensing->state($manifest),
                ],
                'conflicts' => $this->conflicts($manifest),
                'update'    => $installed !== null ? ['from' => $installed->version, 'to' => $manifest->version] : null,
            ];
        } finally {
            File::deleteDirectory($staged['staging']);
        }
    }

    /**
     * Feature keys this package would claim that core, or a different add-on,
     * already owns. Its own earlier version owning them is no conflict.
     *
     * @return list<string>
     */
    public function conflicts(AddonManifest $manifest): array
    {
        $conflicts = [];
        $catalog   = (array) config('feature_catalog.features', []);

        foreach ($manifest->featureKeys() as $key) {
            $owner = is_array($catalog[$key] ?? null) ? ($catalog[$key]['addon'] ?? null) : null;

            if (isset($catalog[$key]) && $owner === null) {
                $conflicts[] = __('Feature :key is already a core feature.', ['key' => $key]);

                continue;
            }

            foreach ($this->registry->all() as $slug => $other) {
                if ($slug !== $manifest->slug && in_array($key, $other->featureKeys(), true)) {
                    $conflicts[] = __('Feature :key is already provided by :addon.', ['key' => $key, 'addon' => $other->name]);
                }
            }
        }

        return $conflicts;
    }

    /**
     * The release key packages are checked against, as a short SHA-256
     * fingerprint an operator can compare with the one Coevs publishes.
     */
    public function keyFingerprint(): ?string
    {
        $key = openssl_pkey_get_public((string) config('project_updater.public_key'));

        if ($key === false) {
            return null;
        }

        $pem = (string) (openssl_pkey_get_details($key)['key'] ?? '');
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '', true);

        if ($der === false || $der === '') {
            return null;
        }

        $hash = strtoupper(hash('sha256', $der));

        return substr($hash, 0, 4).' ··· '.substr($hash, -4);
    }

    private function fileCount(string $zipPath): int
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return 0;
        }

        $count = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            if (! str_ends_with((string) $zip->getNameIndex($index), '/')) {
                $count++;
            }
        }

        $zip->close();

        return $count;
    }
}
