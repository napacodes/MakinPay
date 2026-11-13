<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Addons\AddonPackage;
use App\Services\Addons\AddonRegistry;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Builds the signed zip an add-on is sold as.
 *
 * The private key never lives in the repository or in config: it is read
 * from the path given on the command line for this one build. The signature
 * is checked against project_updater.public_key before the zip is kept, so a
 * package signed with the wrong key - one every buyer's site would refuse -
 * never leaves this machine.
 */
class PackageAddonCommand extends Command
{
    protected $signature = 'addon:package
        {slug : The add-on folder under addons/}
        {--key= : Path to the PEM private release key}
        {--output= : Directory for the zip (default storage/app/addon-builds)}';

    protected $description = 'Build and sign an add-on package for distribution.';

    /**
     * Never shipped inside a package, at any depth.
     */
    private const SKIPPED = ['.git', '.github', '.idea', '.vscode', 'node_modules', 'tests', '.DS_Store', 'Thumbs.db', '.env'];

    public function handle(AddonRegistry $registry): int
    {
        $slug = (string) $this->argument('slug');

        $registry->refresh();
        $manifest = $registry->find($slug);

        if ($manifest === null) {
            foreach ($registry->problems()[$slug] ?? ["No add-on named {$slug} in {$registry->path()}."] as $problem) {
                $this->components->error($problem);
            }

            return self::FAILURE;
        }

        $keyPath = (string) $this->option('key');

        if ($keyPath === '' || ! is_file($keyPath)) {
            $this->components->error('Pass --key=<path to the PEM private release key>.');

            return self::FAILURE;
        }

        $privateKey = openssl_pkey_get_private((string) file_get_contents($keyPath));

        if ($privateKey === false) {
            $this->components->error('The key file is not a readable PEM private key.');

            return self::FAILURE;
        }

        $outputDirectory = rtrim(str_replace('\\', '/', (string) ($this->option('output') ?: storage_path('app/addon-builds'))), '/');
        $zipPath         = $outputDirectory.'/'.$slug.'-'.$manifest->version.'.zip';

        File::ensureDirectoryExists($outputDirectory);
        File::delete($zipPath);

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->components->error("Could not create {$zipPath}.");

            return self::FAILURE;
        }

        $files = $this->files($manifest->basePath);

        foreach ($files as $relative => $absolute) {
            $zip->addFile($absolute, $slug.'/'.$relative);
        }

        $zip->close();

        $zip->open($zipPath);
        $digest = AddonPackage::digest($zip);
        openssl_sign($digest, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $zip->setArchiveComment(AddonPackage::SIGNATURE_PREFIX.base64_encode($signature));
        $zip->close();

        $publicKey = (string) config('project_updater.public_key');

        if (trim($publicKey) === '' || openssl_verify($digest, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            File::delete($zipPath);
            $this->components->error('This key does not match project_updater.public_key, so every site would refuse the package. Nothing was written.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('Packaged %s %s (%d files): %s', $manifest->name, $manifest->version, count($files), $zipPath));

        return self::SUCCESS;
    }

    /**
     * The add-on's shippable files, relative path => absolute path, sorted.
     * Symbolic links are left out: a package that carries one is refused on
     * install.
     *
     * @return array<string, string>
     */
    private function files(string $root): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (array_intersect(explode('/', $relative), self::SKIPPED) !== []) {
                continue;
            }

            $files[$relative] = $file->getPathname();
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
