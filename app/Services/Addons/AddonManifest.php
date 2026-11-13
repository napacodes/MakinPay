<?php

declare(strict_types=1);

namespace App\Services\Addons;

use Illuminate\Support\Str;

/**
 * An add-on's addon.json, read and validated.
 *
 * The manifest is the add-on's whole contract with core: what it is, which
 * core version and PHP extensions it needs, the service provider core boots
 * for it, and the features, permissions and admin menus it contributes.
 * Feature entries use the config/feature_catalog.php shape, so the catalog,
 * FeatureManager and the existing middleware treat them like any other
 * feature.
 *
 * Code lives under addons/<slug>/src in the namespace Addons\<Studly slug>\,
 * which core autoloads itself: buyers cannot run composer.
 */
final class AddonManifest
{
    public const FILE = 'addon.json';

    public const DEFAULT_MIGRATIONS = 'database/migrations';

    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

    /**
     * Snake case only. A comma would split the key inside the
     * `feature.enabled:<key>,<panel>` middleware parameter.
     */
    private const FEATURE_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const EXTENSION_PATTERN = '/^[a-z0-9_]+$/';

    private const PERMISSION_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * @param list<string>                        $phpExtensions
     * @param array<string, array<string, mixed>> $features
     * @param list<string>                        $permissions
     * @param list<array<string, mixed>>          $adminMenus
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $publisher,
        public readonly string $version,
        public readonly string $requiresCore,
        public readonly ?string $description,
        public readonly array $phpExtensions,
        public readonly bool $requiresCron,
        public readonly bool $requiresQueue,
        /** @var array<string, string> slug => lowest version that must be installed (and running, to enable) */
        public readonly array $requiresAddons,
        /** @var array<string, string> slug => lowest version this add-on can work beside, when that one is installed at all */
        public readonly array $worksWith,
        public readonly string $provider,
        public readonly array $features,
        public readonly array $permissions,
        public readonly array $adminMenus,
        public readonly string $migrations,
        public readonly ?int $itemId,
        public readonly string $basePath,
    ) {}

    /**
     * @throws InvalidAddonManifest
     */
    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidAddonManifest($path, ['The manifest file is missing.']);
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidAddonManifest($path, ['The manifest is not a JSON object.']);
        }

        return self::fromArray($data, dirname($path));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidAddonManifest
     */
    public static function fromArray(array $data, string $basePath): self
    {
        $errors = [];

        $slug         = self::requiredString($data, 'slug', $errors);
        $name         = self::requiredString($data, 'name', $errors);
        $publisher    = self::requiredString($data, 'publisher', $errors);
        $version      = self::requiredString($data, 'version', $errors);
        $requiresCore = self::requiredString($data, 'requires_core', $errors);
        $provider     = self::requiredString($data, 'provider', $errors);

        if ($slug !== '' && (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) < 3 || strlen($slug) > 64)) {
            $errors[] = 'slug must be 3-64 lowercase letters, digits and single dashes.';
        }

        if ($name !== '' && mb_strlen($name) > 100) {
            $errors[] = 'name must be at most 100 characters.';
        }

        foreach (['version' => $version, 'requires_core' => $requiresCore] as $field => $value) {
            if ($value !== '' && preg_match(self::VERSION_PATTERN, $value) !== 1) {
                $errors[] = "{$field} must look like 1.2.3.";
            }
        }

        $namespace = self::namespaceFor($slug);

        if ($provider !== '' && (preg_match(self::CLASS_PATTERN, $provider) !== 1 || ! str_starts_with($provider, $namespace))) {
            $errors[] = "provider must be a class in the {$namespace} namespace.";
        }

        $description = $data['description'] ?? null;

        if ($description !== null && ! is_string($description)) {
            $errors[]    = 'description must be a string.';
            $description = null;
        }

        $phpExtensions = self::stringList($data, 'php_extensions', self::EXTENSION_PATTERN, $errors);
        $permissions   = self::stringList($data, 'permissions', self::PERMISSION_PATTERN, $errors);

        $requires = $data['requires'] ?? [];

        if (! is_array($requires)) {
            $errors[] = 'requires must be an object.';
            $requires = [];
        }

        $requiresAddons = self::addonVersions($requires['addons'] ?? [], 'requires.addons', $errors);
        $worksWith      = self::addonVersions($data['works_with'] ?? [], 'works_with', $errors);

        if ($slug !== '' && (isset($requiresAddons[$slug]) || isset($worksWith[$slug]))) {
            $errors[] = 'an add-on cannot require itself.';
        }

        $features = $data['features'] ?? [];

        if (! is_array($features) || ($features !== [] && array_is_list($features))) {
            $errors[] = 'features must be an object keyed by feature key.';
            $features = [];
        }

        foreach ($features as $key => $feature) {
            if (! is_string($key) || preg_match(self::FEATURE_KEY_PATTERN, $key) !== 1) {
                $errors[] = "feature key \"{$key}\" must be snake_case.";

                continue;
            }

            if (! is_array($feature) || ! self::isFilledString($feature['label'] ?? null) || ! self::isFilledString($feature['category'] ?? null)) {
                $errors[] = "feature {$key} needs a label and a category.";
            }
        }

        $adminMenus = $data['admin_menus'] ?? [];

        if (! is_array($adminMenus) || ! array_is_list($adminMenus) || array_filter($adminMenus, fn ($menu): bool => ! is_array($menu)) !== []) {
            $errors[]   = 'admin_menus must be a list of menu entries.';
            $adminMenus = [];
        }

        $migrations = $data['migrations'] ?? self::DEFAULT_MIGRATIONS;

        if (! is_string($migrations) || ! self::isRelativeInsidePath($migrations)) {
            $errors[]   = 'migrations must be a relative path inside the add-on.';
            $migrations = self::DEFAULT_MIGRATIONS;
        }

        $itemId = $data['item_id'] ?? null;

        if ($itemId !== null && (! is_int($itemId) || $itemId < 1)) {
            $errors[] = 'item_id must be a positive integer.';
            $itemId   = null;
        }

        if ($errors !== []) {
            throw new InvalidAddonManifest(rtrim($basePath, '/\\').'/'.self::FILE, $errors);
        }

        return new self(
            slug: $slug,
            name: $name,
            publisher: $publisher,
            version: $version,
            requiresCore: $requiresCore,
            description: $description,
            phpExtensions: $phpExtensions,
            requiresCron: ($requires['cron'] ?? false)   === true,
            requiresQueue: ($requires['queue'] ?? false) === true,
            requiresAddons: $requiresAddons,
            worksWith: $worksWith,
            provider: $provider,
            features: $features,
            permissions: $permissions,
            adminMenus: $adminMenus,
            migrations: trim(str_replace('\\', '/', $migrations), '/'),
            itemId: $itemId,
            basePath: rtrim(str_replace('\\', '/', $basePath), '/'),
        );
    }

    /**
     * The PSR-4 prefix core registers for this add-on, e.g. Addons\OnchainDeposits\.
     */
    public function namespace(): string
    {
        return self::namespaceFor($this->slug);
    }

    public function sourcePath(): string
    {
        return $this->basePath.'/src';
    }

    /**
     * Where the provider class must live under the PSR-4 mapping.
     */
    public function providerPath(): string
    {
        $relative = substr($this->provider, strlen($this->namespace()));

        return $this->sourcePath().'/'.str_replace('\\', '/', $relative).'.php';
    }

    public function migrationsPath(): string
    {
        return $this->basePath.'/'.$this->migrations;
    }

    public function isCompatibleWith(string $coreVersion): bool
    {
        return version_compare($coreVersion, $this->requiresCore, '>=');
    }

    /**
     * The required PHP extensions this server lacks.
     *
     * @param  (callable(string): bool)|null $isLoaded defaults to extension_loaded
     * @return list<string>
     */
    public function missingExtensions(?callable $isLoaded = null): array
    {
        $isLoaded ??= extension_loaded(...);

        return array_values(array_filter($this->phpExtensions, fn (string $extension): bool => ! $isLoaded($extension)));
    }

    /**
     * @return list<string>
     */
    public function featureKeys(): array
    {
        return array_keys($this->features);
    }

    /**
     * Other add-ons by slug, each with the lowest version wanted: "1.2.0" or
     * ">=1.2.0" (the only comparison an add-on may ask for, so a newer
     * version of the other add-on never locks this one out).
     *
     * @param  list<string>          $errors
     * @return array<string, string>
     */
    private static function addonVersions(mixed $value, string $field, array &$errors): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = "{$field} must be an object keyed by add-on slug.";

            return [];
        }

        $versions = [];

        foreach ($value as $slug => $version) {
            if (! is_string($slug) || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
                $errors[] = "{$field} slug \"{$slug}\" must be lowercase letters, digits and single dashes.";

                continue;
            }

            $minimum = is_string($version) ? ltrim(trim($version), '>=') : '';

            if ($minimum === '' || preg_match(self::VERSION_PATTERN, $minimum) !== 1) {
                $errors[] = "{$field}.{$slug} must be a version like 1.2.0 or >=1.2.0.";

                continue;
            }

            $versions[$slug] = $minimum;
        }

        return $versions;
    }

    private static function namespaceFor(string $slug): string
    {
        return 'Addons\\'.Str::studly($slug).'\\';
    }

    private static function isFilledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function isRelativeInsidePath(string $path): bool
    {
        $normalised = str_replace('\\', '/', $path);

        return $normalised !== ''
            && ! str_starts_with($normalised, '/')
            && preg_match('/^[A-Za-z]:/', $normalised) !== 1
            && ! in_array('..', explode('/', $normalised), true);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private static function requiredString(array $data, string $field, array &$errors): string
    {
        $value = $data[$field] ?? null;

        if (! self::isFilledString($value)) {
            $errors[] = "{$field} is required.";

            return '';
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed> $data
     * @param  list<string>         $errors
     * @return list<string>
     */
    private static function stringList(array $data, string $field, string $pattern, array &$errors): array
    {
        $values = $data[$field] ?? [];

        if (! is_array($values) || ! array_is_list($values)) {
            $errors[] = "{$field} must be a list.";

            return [];
        }

        $valid = [];

        foreach ($values as $value) {
            if (! is_string($value) || preg_match($pattern, $value) !== 1) {
                $errors[] = "{$field} has an invalid entry.";

                continue;
            }

            $valid[] = $value;
        }

        return array_values(array_unique($valid));
    }
}
