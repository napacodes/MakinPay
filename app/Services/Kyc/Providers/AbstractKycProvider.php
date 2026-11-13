<?php

namespace App\Services\Kyc\Providers;

use App\Contracts\Kyc\KycVerificationProviderInterface;
use App\Models\KycProvider;
use App\Models\KycSubmission;
use App\Support\KycDocumentDisk;
use App\Support\KycQueueRow;
use Illuminate\Support\Str;

/**
 * Shared plumbing for configured KYC drivers (everything except the manual
 * null-object). Holds the admin-configured KycProvider row and exposes helpers
 * for credentials, document extraction, and a stable per-submission reference.
 */
abstract class AbstractKycProvider implements KycVerificationProviderInterface
{
    public function __construct(protected KycProvider $config) {}

    protected function credential(string $key, mixed $default = null): mixed
    {
        return $this->config->credential($key, $default);
    }

    /**
     * True when every listed credential key has a non-empty value, so a driver
     * can cheaply decide whether it is configured enough to call out.
     *
     * @param array<int, string> $keys
     */
    protected function hasCredentials(array $keys): bool
    {
        foreach ($keys as $key) {
            if (blank($this->credential($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * A deterministic reference we can reconcile a webhook back to this
     * submission with (providers echo their own id too, but this guarantees a
     * link even when they don't).
     */
    protected function reference(KycSubmission $submission): string
    {
        return 'DK-KYC-'.$submission->id.'-'.Str::lower(Str::random(8));
    }

    /**
     * Best-effort extraction of the primary ID document + selfie file paths
     * from the template-driven submission_data.
     *
     * @return array{id_document: ?string, selfie: ?string}
     */
    protected function documents(KycSubmission $submission): array
    {
        $data = $submission->submission_data ?? [];

        /*
         * Both generations. This matched only `files/`, which was every
         * document until they were moved off the public disk — so a
         * submission taken since then would have handed a provider nothing
         * at all, quietly. KycQueueRow::isDocumentPath is the predicate the
         * reviewer's viewer and the fingerprinter already use, so all three
         * agree on what counts as a document.
         */
        $files = collect($data)->filter(
            fn (mixed $value): bool => KycQueueRow::isDocumentPath($value)
        )->values();

        return [
            'id_document' => $data['id_document'] ?? $files->first(),
            'selfie'      => $data['selfie']      ?? $data['user_photo'] ?? $files->get(1),
        ];
    }

    /**
     * Read one stored document off whichever disk holds it.
     *
     * Identity documents live on the private disk now, and older ones are
     * still on the public one; KycDocumentDisk knows which. Returns null
     * rather than throwing when a path is missing, because a submission whose
     * file has gone is a case for a human, not an exception on a queue.
     *
     * @return array{bytes: string, mime: string, size: int}|null
     */
    protected function readDocument(?string $stored): ?array
    {
        if (blank($stored) || ! KycDocumentDisk::exists($stored)) {
            return null;
        }

        [$disk, $path] = KycDocumentDisk::locate($stored);

        $bytes = $disk->get($path);

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime'  => match (Str::lower(pathinfo($path, PATHINFO_EXTENSION))) {
                'png'   => 'image/png',
                'pdf'   => 'application/pdf',
                'webp'  => 'image/webp',
                default => 'image/jpeg',
            },
            'size' => strlen($bytes),
        ];
    }

    public function driver(): string
    {
        return $this->config->driver;
    }

    /**
     * The administrator-given name for this connection. Shown to the applicant
     * before they are handed over, so they recognise where they are going.
     */
    public function providerName(): string
    {
        return (string) $this->config->name;
    }
}
