<?php

declare(strict_types=1);

namespace App\Services\Addons;

/**
 * Every add-on present on disk under addons/<slug>/addon.json.
 *
 * Presence is not installation: an add-on is installed when it has a row in
 * the `addons` table. The registry only answers what the code on disk is and
 * whether it can be trusted. A folder that fails any check is left out of
 * all() and reported by problems() instead, so one broken upload never stops
 * core from booting.
 */
final class AddonRegistry
{
    /**
     * @var array<string, AddonManifest>|null
     */
    private ?array $manifests = null;

    /**
     * @var array<string, list<string>>
     */
    private array $problems = [];

    public function __construct(private readonly ?string $addonsPath = null) {}

    public function path(): string
    {
        return rtrim(str_replace('\\', '/', $this->addonsPath ?? base_path('addons')), '/');
    }

    /**
     * @return array<string, AddonManifest> keyed by slug, sorted by slug
     */
    public function all(): array
    {
        return $this->manifests ??= $this->discover();
    }

    public function find(string $slug): ?AddonManifest
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * Folders that were refused, with every reason.
     *
     * @return array<string, list<string>> keyed by folder name
     */
    public function problems(): array
    {
        $this->all();

        return $this->problems;
    }

    public function coreVersion(): string
    {
        return (string) config('project_updater.version', '0.0.0');
    }

    public function isCompatible(AddonManifest $manifest): bool
    {
        return $manifest->isCompatibleWith($this->coreVersion());
    }

    public function refresh(): void
    {
        $this->manifests = null;
        $this->problems  = [];
    }

    /**
     * @return array<string, AddonManifest>
     */
    private function discover(): array
    {
        $this->problems = [];

        $files = glob($this->path().'/*/'.AddonManifest::FILE) ?: [];
        sort($files);

        $coreKeys  = $this->coreFeatureKeys();
        $claimedBy = [];
        $manifests = [];

        foreach ($files as $file) {
            $folder = basename(dirname($file));

            try {
                $manifest = AddonManifest::fromFile($file);
            } catch (InvalidAddonManifest $invalid) {
                $this->problems[$folder] = $invalid->errors;

                continue;
            }

            $errors = [];

            if ($manifest->slug !== $folder) {
                $errors[] = "The folder must be named after the slug ({$manifest->slug}).";
            }

            if (! is_file($manifest->providerPath())) {
                $errors[] = "The provider class {$manifest->provider} has no file at src/.";
            }

            foreach ($manifest->featureKeys() as $key) {
                if (in_array($key, $coreKeys, true)) {
                    $errors[] = "Feature {$key} is already a core feature.";
                } elseif (isset($claimedBy[$key])) {
                    $errors[] = "Feature {$key} is already provided by {$claimedBy[$key]}.";
                }
            }

            if ($errors !== []) {
                $this->problems[$folder] = $errors;

                continue;
            }

            foreach ($manifest->featureKeys() as $key) {
                $claimedBy[$key] = $manifest->slug;
            }

            $manifests[$manifest->slug] = $manifest;
        }

        return $manifests;
    }

    /**
     * Catalog keys that belong to core. Entries an add-on merged into the
     * catalog carry an `addon` marker and are not core.
     *
     * @return list<string>
     */
    private function coreFeatureKeys(): array
    {
        $catalog = (array) config('feature_catalog.features', []);

        return array_keys(array_filter($catalog, fn ($feature): bool => ! is_array($feature) || ! isset($feature['addon'])));
    }
}
