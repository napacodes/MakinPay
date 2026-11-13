<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CustomLanding;
use App\Services\CustomLandingHtmlCompiler;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Publishes the landing-page bundles that ship with the product.
 *
 * Until now every custom landing arrived through the admin ZIP upload, so a
 * landing designed for a release could not reach an installation without
 * somebody uploading it by hand. This seeder closes that gap: bundles committed
 * under `database/seeders/assets/landings/<folder>/` are copied into
 * `public/custom-landings/<folder>/`, run through the same
 * {@see CustomLandingHtmlCompiler} the upload path uses, and recorded in
 * `custom_landings`.
 *
 *     php artisan db:seed --class=CustomLandingSeeder
 *
 * Additive and upgrade-safe:
 *   - A folder that already owns a row is skipped outright — files included.
 *     An administrator may have rewritten the HTML from Landing Page → Manage
 *     HTML, or deactivated the page; a replay on update must undo neither.
 *   - New rows are seeded INACTIVE. `HomeController` serves the active landing
 *     in place of the page builder, so activating on seed would silently
 *     replace an existing homepage during an update. The administrator opts in
 *     from Landing Page → Activate.
 *   - A missing bundle directory is a skip, not a failure, so the seeder stays
 *     safe in `installer.reconcile_seeders`.
 */
class CustomLandingSeeder extends Seeder
{
    /** Where the committed bundles live. */
    protected string $assetRoot = '';

    public function __construct(protected readonly CustomLandingHtmlCompiler $htmlCompiler) {}

    /**
     * Bundles that ship with the product, keyed by published folder name.
     *
     * The folder is stable — unlike an admin upload, which appends `time()` —
     * so a replay recognises the row it created last time instead of
     * publishing a second copy under a fresh name.
     *
     * @return array<string, string>
     */
    protected function landings(): array
    {
        return [
            'digikash-landing-v3' => 'DigiKash Landing v3',
        ];
    }

    public function run(): void
    {
        if (! Schema::hasTable('custom_landings')) {
            return;
        }

        $this->assetRoot = base_path('database/seeders/assets/landings');

        foreach ($this->landings() as $folder => $name) {
            $this->seedLanding($folder, $name);
        }
    }

    /**
     * Publish one bundle and record it, unless it is already installed.
     */
    protected function seedLanding(string $folder, string $name): void
    {
        if (CustomLanding::query()->where('folder', $folder)->exists()) {
            return;
        }

        $source = $this->assetRoot.DIRECTORY_SEPARATOR.$folder;

        if (! File::isDirectory($source) || ! File::exists($source.DIRECTORY_SEPARATOR.'index.html')) {
            $this->command?->warn("[CustomLandingSeeder] bundle missing, skipped: {$folder}");

            return;
        }

        $target = $this->publish($source, $folder);
        $stats  = $this->bundleStats($target);

        CustomLanding::query()->create([
            'name'              => $name,
            'folder'            => $folder,
            'status'            => false,
            'file_count'        => $stats['file_count'],
            'total_size'        => $stats['total_size'],
            'source_checksum'   => $stats['source_checksum'],
            'last_validated_at' => now(),
        ]);
    }

    /**
     * Copy a bundle into the public landing folder and compile its index.
     *
     * The folder is ours to own: `seedLanding()` only reaches here when no row
     * claims it, so a leftover directory from a deleted landing is replaced
     * rather than merged into.
     *
     * @return string the published directory
     */
    protected function publish(string $source, string $folder): string
    {
        $target = public_path("custom-landings/{$folder}");

        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        File::ensureDirectoryExists($target, 0755, true);

        // Deliberately not File::copyDirectory(): its FilesystemIterator skips
        // only `.` and `..`, so a stray dotfile would be published, go
        // uncounted by bundleStats() — File::allFiles() ignores dotfiles — and
        // break a re-upload of the same bundle, because the archive validator
        // rejects any path segment beginning with a dot. Copying exactly the
        // file list bundleStats() measures keeps the two in step.
        foreach (File::allFiles($source) as $file) {
            $destination = $target.DIRECTORY_SEPARATOR.$file->getRelativePathname();

            File::ensureDirectoryExists(dirname($destination), 0755, true);
            File::copy($file->getPathname(), $destination);
        }

        $index = $target.DIRECTORY_SEPARATOR.'index.html';

        File::replace($index, $this->htmlCompiler->compileForPublish(File::get($index), $folder));

        return $target;
    }

    /**
     * Fingerprint the published bundle so a later revision can be detected.
     *
     * @return array{file_count: int, total_size: int, source_checksum: string}
     */
    protected function bundleStats(string $path): array
    {
        $files = collect(File::allFiles($path))
            ->sortBy(fn (SplFileInfo $file): string => $file->getRelativePathname())
            ->values();

        $fingerprint = $files
            ->map(fn (SplFileInfo $file): string => str_replace('\\', '/', $file->getRelativePathname())
                .':'.(hash_file('sha256', $file->getPathname()) ?: ''))
            ->implode("\n");

        return [
            'file_count'      => $files->count(),
            'total_size'      => (int) $files->sum(fn (SplFileInfo $file): int => $file->getSize()),
            'source_checksum' => hash('sha256', $fingerprint),
        ];
    }
}
