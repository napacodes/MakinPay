<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KycSubmission;
use App\Support\KycDocumentDisk;
use App\Support\KycQueueRow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Move identity documents off the public disk.
 *
 * Every KYC document taken before the documents were made private was written
 * to storage/app/public, which the storage symlink exposes to the web: a
 * passport answered to anyone holding its URL, with no session and no
 * permission anywhere along the way. New uploads go to a private disk now,
 * but the ones already on an installation stay exactly where they were until
 * somebody moves them, and nobody but the operator should decide when to
 * touch their data.
 *
 * Safe to interrupt and safe to repeat. Each document is copied, verified by
 * size, and only then removed from the public disk, so a crash midway leaves
 * the file readable in one place or the other and never in neither. A second
 * run skips what it already did.
 */
class PrivatiseKycDocuments extends Command
{
    protected $signature = 'kyc:privatise-documents
                            {--dry-run : List what would move without touching anything}';

    protected $description = 'Move KYC identity documents off the public disk into private storage';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $public = Storage::disk('public');
        $kyc    = Storage::disk(KycDocumentDisk::DISK);

        $moved   = 0;
        $missing = 0;
        $already = 0;
        $failed  = [];

        foreach (KycSubmission::query()->whereNotNull('submission_data')->cursor() as $submission) {
            $data    = $submission->submission_data ?? [];
            $changed = false;

            foreach ($data as $label => $value) {
                if (! KycQueueRow::isDocumentPath($value) || ! KycDocumentDisk::isLegacy((string) $value)) {
                    continue;
                }

                $from = KycDocumentDisk::normalise((string) $value);

                /*
                 * Under legacy/ rather than mixed in with new uploads: the old
                 * names came from the shared uploader and the new ones are
                 * uuids, so keeping the two apart means a move can never land
                 * on top of something already there.
                 */
                $to = KycDocumentDisk::PREFIX.'/legacy/'.$from;

                if ($kyc->exists($to) && ! $public->exists($from)) {
                    // An earlier run moved the file but stopped before the row.
                    $data[$label] = $to;
                    $changed      = true;
                    $already++;

                    continue;
                }

                if (! $public->exists($from)) {
                    $this->warn("missing on disk, left alone: {$from}");
                    $missing++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("would move  {$from}  ->  {$to}");
                    $moved++;

                    continue;
                }

                try {
                    $stream = $public->readStream($from);

                    if (! is_resource($stream)) {
                        throw new RuntimeException('could not open for reading');
                    }

                    $kyc->writeStream($to, $stream);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    // Verified before the original goes, never after.
                    if ($kyc->size($to) !== $public->size($from)) {
                        throw new RuntimeException('copy size did not match the original');
                    }

                    $public->delete($from);

                    $data[$label] = $to;
                    $changed      = true;
                    $moved++;
                } catch (Throwable $e) {
                    $failed[] = $from.' — '.$e->getMessage();
                }
            }

            if ($changed && ! $dryRun) {
                $submission->submission_data = $data;
                $submission->save();
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "{$moved} document(s) would move."
            : "{$moved} document(s) moved, {$already} already private, {$missing} missing.");

        foreach ($failed as $failure) {
            $this->error('failed: '.$failure);
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
