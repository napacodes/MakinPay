<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectUpdate extends Model
{
    protected $fillable = [
        'version',
        'version_from',
        'channel',
        'status',
        'package_url',
        'checksum',
        'signature',
        'changelog',
        'requirements',
        'package_path',
        'backup_path',
        'release_date',
        'checked_at',
        'installed_at',
        'duration_ms',
        'installed_by',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'changelog'    => 'array',
            'requirements' => 'array',
            'metadata'     => 'array',
            'duration_ms'  => 'integer',
            'release_date' => 'datetime',
            'checked_at'   => 'datetime',
            'installed_at' => 'datetime',
        ];
    }

    /** Memoised answer for backupIsOnDisk(), which the history asks repeatedly. */
    private ?bool $backupOnDisk = null;

    /**
     * `rolled_back` is installable again on purpose: backing a release out
     * leaves it still ahead of you, and re-running the real installer — with
     * its checksum, its snapshot and its migrations — is a far better way
     * back than restoring raw files from a folder.
     */
    public function isInstallable(): bool
    {
        return in_array($this->status, ['available', 'downloaded', 'failed', 'rolled_back'], true);
    }

    /**
     * May this row be rolled back, and if not, why not?
     *
     * Rollback restores the files ONE install replaced, so it only makes
     * sense against the install that is currently in force — or against one
     * that failed and left its snapshot behind. Offering it on a release from
     * three versions ago would write files from that era over an application
     * whose database has since run every migration in between, which is a
     * different and far worse operation than the word "roll back" implies.
     *
     * Returns null when the row can be rolled back.
     */
    public function rollbackBlockedReason(): ?string
    {
        if (! $this->wasInstallAttempt()) {
            return __('This release was never installed.');
        }

        if (blank($this->backup_path)) {
            return __('No snapshot was taken for this install.');
        }

        if (! $this->backupIsOnDisk()) {
            return __('The snapshot for this install is no longer on disk.');
        }

        /*
         * The failed row is the exception: its install never took, so the
         * running version is still the one its snapshot holds.
         */
        if ($this->status !== 'failed' && $this->version !== (string) config('app.version')) {
            return __('Only the release currently in force can be rolled back.');
        }

        return null;
    }

    public function canRollBack(): bool
    {
        return $this->rollbackBlockedReason() === null;
    }

    /**
     * Is this release actually ahead of the code that is running?
     *
     * `status` is decided at check time and then stored, so a row marked
     * `available` five months ago is still marked `available` after three
     * releases have been installed over it. Reading that stored word is how
     * the page came to offer v2.0.1 to a 2.6.4 installation. The version
     * comparison is the only thing that stays true without being re-checked.
     */
    public function isNewerThanInstalled(): bool
    {
        return version_compare((string) $this->version, (string) config('app.version'), '>');
    }

    /**
     * Did this row ever become an install, or is it only a check result?
     *
     * Most rows are checks. The history table has to say which is which, or
     * it prints a duration, an actor and a backup for a release nobody ever
     * installed — four empty columns and no explanation.
     */
    public function wasInstallAttempt(): bool
    {
        return $this->installed_at !== null || in_array($this->status, ['failed', 'rolled_back'], true);
    }

    /**
     * What the release server told us about this package.
     *
     * The payload is stored verbatim in `metadata`, and the server nests the
     * two facts the page most wants: the download size under
     * `metadata.file_size` and the file count under `source.file_count`.
     * Older payload shapes put them at the top level. Everything is read
     * through data_get() with the known aliases so a missing key becomes
     * null and the page prints an em dash instead of a confident zero.
     *
     * @return array{bytes: float|null, files: int|null, migrations: int|null, filename: string|null, repo: string|null, ref: string|null, commit: string|null, forced: bool, signed: bool}
     */
    public function releaseFacts(): array
    {
        $meta = $this->metadata ?? [];

        $first = function (array $keys) use ($meta) {
            foreach ($keys as $key) {
                $value = data_get($meta, $key);

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return null;
        };

        $bytes      = $first(['metadata.file_size', 'file_size', 'size', 'package_size', 'bytes']);
        $files      = $first(['source.file_count', 'metadata.file_count', 'file_count', 'files']);
        $migrations = $first(['metadata.migrations', 'migrations', 'migration_count']);

        return [
            'bytes'      => is_numeric($bytes) ? (float) $bytes : null,
            'files'      => is_numeric($files) ? (int) $files : null,
            'migrations' => is_numeric($migrations) ? (int) $migrations : null,
            'filename'   => ($name = $first(['filename', 'package_filename'])) ? (string) $name : null,
            'repo'       => ($repo = $first(['source.repo'])) ? (string) $repo : null,
            'ref'        => ($ref = $first(['source.ref'])) ? (string) $ref : null,
            'commit'     => ($commit = $first(['source.commit'])) ? (string) $commit : null,
            'forced'     => (bool) $first(['is_force', 'forced']),
            /*
             * Signed and checksum-verified are two different promises. Real
             * releases currently ship `signature: null`, so the page may not
             * print "signed" for them — only the sha256 is actually checked.
             */
            'signed' => filled($this->signature),
        ];
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'installed'   => 'success',
            'failed'      => 'danger',
            'rolled_back' => 'warning',
            'downloaded'  => 'info',
            'current'     => 'secondary',
            default       => 'warning',
        };
    }

    /**
     * What the history row says happened, in the operator's language.
     *
     * A row that was never installed is a check result, and saying "Current"
     * for three different old versions — as the stored status does — claims
     * three things that cannot all be true. Checks say what the check found.
     */
    public function resultLabel(): string
    {
        return match ($this->status) {
            'installed'   => __('Installed'),
            'failed'      => __('Failed'),
            'rolled_back' => __('Rolled back'),
            'downloaded'  => __('Downloaded'),
            'current'     => __('No update'),
            'available'   => $this->isNewerThanInstalled() ? __('Offered') : __('Superseded'),
            default       => title(str_replace('_', ' ', (string) $this->status)),
        };
    }

    /**
     * The half-sentence under the result, saying what the row actually is.
     */
    public function resultNote(): string
    {
        if ($this->status === 'failed') {
            return filled($this->error_message)
                ? Str::limit((string) $this->error_message, 70)
                : __('install stopped before it finished');
        }

        if ($this->status === 'rolled_back') {
            return filled($this->version_from)
                ? __('reverted to v:version', ['version' => $this->version_from])
                : __('files reverted from the snapshot');
        }

        return match ($this->status) {
            'installed'  => __('installed on this site'),
            'downloaded' => __('package downloaded, not installed'),
            'current'    => __('this site was up to date'),
            'available'  => $this->isNewerThanInstalled()
                ? __('offered, not installed yet')
                : __('older than the running version'),
            default => (string) $this->channel,
        };
    }

    /**
     * Is the snapshot this row recorded still on disk?
     *
     * The history table may not say "Available" on the strength of a stored
     * path alone: backups are pruned by hand and by hosting panels, and a row
     * that promises a rollback target which no longer exists is worse than
     * one that admits the snapshot expired.
     */
    public function backupIsOnDisk(): bool
    {
        if (blank($this->backup_path)) {
            return false;
        }

        /*
         * Memoised for the life of the instance. The history partial asks each
         * row three times — once for the Backup column, once through
         * canRollBack() and once more for the disabled button's reason — so
         * ten rows meant thirty filesystem round trips per render, measured at
         * 6.5 ms on a local disk and considerably worse on networked storage.
         */
        if ($this->backupOnDisk !== null) {
            return $this->backupOnDisk;
        }

        try {
            return $this->backupOnDisk = Storage::disk((string) config('project_updater.storage_disk'))
                ->exists((string) $this->backup_path);
        } catch (\Throwable) {
            return $this->backupOnDisk = false;
        }
    }

    /**
     * "2 m 10 s" — how long the install ran.
     *
     * Null for every row written before the duration column existed, which
     * the page prints as an em dash rather than a misleading zero.
     */
    public function durationForHumans(): ?string
    {
        $ms = $this->duration_ms;

        if ($ms === null || $ms <= 0) {
            return null;
        }

        /*
         * A rollback is a file copy and finishes in well under a second, which
         * rounded to a flat "0 s" — a duration that reads as "nothing
         * happened" next to a row that plainly did something.
         */
        if ($ms < 1000) {
            return __('0.:tenths s', ['tenths' => max(1, (int) round($ms / 100))]);
        }

        $seconds = (int) round($ms / 1000);

        if ($seconds < 60) {
            return __(':seconds s', ['seconds' => $seconds]);
        }

        return __(':minutes m :seconds s', [
            'minutes' => intdiv($seconds, 60),
            'seconds' => str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT),
        ]);
    }
}
