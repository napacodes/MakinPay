<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\KycVerificationMethod;
use App\Models\KycSubmission;
use Carbon\CarbonInterface;
use Illuminate\Support\HtmlString;

/**
 * The readings the two KYC tables put on a row: how long it has waited,
 * and what the verification provider made of it.
 *
 * Both are derived from columns the submission already carries. Nothing here
 * touches the database or calls a service — the queue renders ten of these a
 * page, and a per-row lookup is how a list turns into thirty queries.
 */
final class KycQueueRow
{
    private function __construct(private readonly KycSubmission $submission) {}

    public static function for(KycSubmission $submission): self
    {
        return new self($submission);
    }

    /**
     * "3d 4h", not "3 days".
     *
     * Two parts and short units, because the queue's Waiting column is
     * narrow and two rows an hour apart have to be comparable at a glance.
     * The stats strip reads the same figure, so both go through here rather
     * than each writing its own option array.
     */
    public static function humanDuration(?CarbonInterface $since): string
    {
        return $since === null ? '—' : $since->diffForHumans([
            'parts'  => 2,
            'short'  => true,
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
        ]);
    }

    /**
     * How long this has been waiting, and how far through the SLA that is.
     *
     * The percentage drives a bar rather than a number, because the useful
     * question on a queue is "which of these is worst" and not "how many
     * hours exactly".
     *
     * @return array{label: string, tone: string, percent: int, at: ?string}
     */
    public function waiting(int $slaHours): array
    {
        $since = $this->submission->created_at;

        if ($since === null) {
            return ['label' => '—', 'tone' => 'neutral', 'percent' => 0, 'at' => null];
        }

        $slaHours = max(1, $slaHours);
        $hours    = $since->diffInHours(now());

        return [
            'label'   => self::humanDuration($since),
            'at'      => $since->format('d M, H:i'),
            'percent' => (int) min(100, round($hours / $slaHours * 100)),
            'tone'    => match (true) {
                $hours >= $slaHours     => 'danger',
                $hours >= $slaHours / 2 => 'warning',
                default                 => 'success',
            },
        ];
    }

    /**
     * The provider's admin-facing name, never the stored slug.
     *
     * `title(str_replace('_', ' ', 'smile_id'))` printed "Smile Id" on every
     * row. The catalog already holds the spelling the vendor uses.
     */
    public static function providerName(?string $provider): ?string
    {
        if (blank($provider)) {
            return null;
        }

        $key     = str_replace('-', '_', $provider);
        $catalog = (array) config('kyc.catalog', []);

        return $catalog[$key]['name'] ?? ucwords(str_replace('_', ' ', $provider));
    }

    /**
     * The accent the provider gallery already uses for this engine, so the
     * queue's little tile and the provider card agree on its colour.
     */
    public static function providerAccent(?string $provider): string
    {
        $key = str_replace('-', '_', (string) $provider);

        return (string) (config('kyc.catalog.'.$key.'.accent') ?? 'slate');
    }

    /**
     * Up to two letters for the provider tile: "S", "SI", "SP".
     */
    public static function providerInitials(?string $provider): string
    {
        $name = self::providerName($provider);

        if ($name === null) {
            return '—';
        }

        /* "ShuftiPro" is one word to a space-splitter and two to a reader. */
        $spaced = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name) ?? $name;
        $words  = array_values(array_filter(preg_split('/[\s_-]+/', $spaced) ?: []));

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 1));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
    }

    /**
     * What the provider said, if a provider was ever asked.
     *
     * The engine stores its verdict on the submission and nothing in the
     * admin has ever read it back, so a reviewer re-did work the provider had
     * already done. "Manual only" is not a failure state — it is the honest
     * reading when no provider was involved at all.
     *
     * @return array{state: string, tone: string, label: string, provider: ?string, name: ?string, initials: string, accent: string, confidence: ?string, detail: ?string, score: ?string, score_tone: string}
     */
    public function providerSignal(): array
    {
        $provider = $this->submission->provider;

        $engine = [
            'provider' => $provider,
            'name'     => self::providerName($provider),
            'initials' => self::providerInitials($provider),
            'accent'   => self::providerAccent($provider),
        ];

        if ($this->submission->verification_method !== KycVerificationMethod::Auto) {
            return $engine + [
                'state'      => 'manual',
                'tone'       => 'neutral',
                'label'      => __('Manual only'),
                'provider'   => null,
                'name'       => null,
                'initials'   => '—',
                'accent'     => 'slate',
                'confidence' => null,
                'detail'     => $this->manualReason(),
                'score'      => null,
                'score_tone' => 'neutral',
            ];
        }

        $verdict = $this->submission->auto_result['verdict'] ?? null;
        $reason  = $this->submission->auto_result['reason']  ?? null;
        $risk    = $this->submission->risk_score;

        if ($verdict === null || $verdict === 'pending') {
            return $engine + [
                'state'      => 'checking',
                'tone'       => 'info',
                'label'      => __('Checking'),
                'confidence' => null,
                'detail'     => __('Sent for verification. The verdict lands here when the provider answers.'),
                'score'      => null,
                'score_tone' => 'neutral',
            ];
        }

        [$state, $tone, $label] = match ($verdict) {
            'clear'    => ['clear', 'success', __('Approve')],
            'rejected' => ['rejected', 'danger', __('Reject')],
            /*
             * Red, not amber. Both boards paint this one red: a submission
             * the engine will not clear is the one that needs a person now,
             * and amber beside a green Approve read as "nearly fine".
             */
            default => ['review', 'danger', __('Needs review')],
        };

        return $engine + [
            'state' => $state,
            'tone'  => $tone,
            'label' => $label,
            /*
             * Only on a clear. The pill's figure is the engine saying how
             * sure it is of a pass; beside "Needs review" it read as
             * "0.18 sure", which the detail line already says the other way
             * round as a risk score — two numbers for one reading.
             */
            'confidence' => $state === 'clear' ? self::confidenceFor($risk) : null,
            'detail'     => $this->detailSentence($reason, $risk),
            'score'      => $risk === null ? null : (string) $risk,
            'score_tone' => self::riskTone($risk),
        ];
    }

    /**
     * Why nobody asked a provider about this one.
     *
     * Two different situations wore one sentence before: a template the admin
     * marked manual-only, and an applicant in a country no enabled provider
     * covers. A reviewer can act on the second and not on the first.
     */
    private function manualReason(): string
    {
        if ($this->submission->kycTemplate?->isManualOnly()) {
            return __('This template is set to manual review, so nothing was sent to a provider.');
        }

        $country = $this->submission->user?->country;

        return blank($country)
            ? __('No provider was asked about this submission.')
            : __(':country is outside the active provider\'s coverage — nothing was sent.', ['country' => $country]);
    }

    /**
     * The detail line, with its figure marked for the monospace face.
     *
     * The sentence is translated whole and the figure substituted into it
     * afterwards, so a translator keeps control of word order and the number
     * still lands in its own element.
     */
    public function detailHtml(): ?HtmlString
    {
        $signal = $this->providerSignal();

        if (blank($signal['detail'])) {
            return null;
        }

        $marked = e($signal['detail']);

        if ($signal['score'] === null) {
            return new HtmlString($marked);
        }

        return new HtmlString(str_replace(
            self::SCORE_SLOT,
            '<span class="kq-score is-'.$signal['score_tone'].'">'.e($signal['score']).'</span>',
            $marked
        ));
    }

    /** Placeholder the detail sentence reserves for its figure. */
    private const SCORE_SLOT = '@@score@@';

    private function detailSentence(?string $reason, ?int $risk): ?string
    {
        if ($risk === null) {
            return $reason;
        }

        return filled($reason)
            ? __(':reason · risk :score of 100', ['reason' => $reason, 'score' => self::SCORE_SLOT])
            : __('Risk score :score of 100', ['score' => self::SCORE_SLOT]);
    }

    /**
     * How sure the engine was, on the 0–1 scale the review screen reads in.
     *
     * Derived from the stored risk score rather than invented: the providers
     * return risk, and confidence is its complement. Nothing else is stored,
     * so nothing else is claimed.
     */
    private static function confidenceFor(?int $risk): ?string
    {
        return $risk === null ? null : number_format((100 - max(0, min(100, $risk))) / 100, 2);
    }

    private static function riskTone(?int $risk): string
    {
        if ($risk === null) {
            return 'neutral';
        }

        return match (true) {
            $risk > (int) config('kyc.thresholds.auto_reject_above', 70)   => 'danger',
            $risk >= (int) config('kyc.thresholds.auto_approve_below', 20) => 'warning',
            default                                                        => 'success',
        };
    }

    /**
     * Who settled this submission, and how.
     *
     * Reads the review log the caller eager-loaded and never loads one
     * itself: the records table draws ten of these a page, and the log is
     * the only place that knows whether a person or the pipeline decided.
     *
     * @return array{label: string, by: string, at: ?string, reason: ?string, remarks: ?string, automated: bool}|null
     */
    public function decision(): ?array
    {
        if (! $this->submission->relationLoaded('reviewLogs')) {
            return null;
        }

        $log = $this->submission->reviewLogs->first();

        if ($log === null) {
            return null;
        }

        $automated = $log->action->isAutomated();

        return [
            'label'     => $log->action->label(),
            'automated' => $automated,
            'by'        => $automated
                ? (self::providerName($this->submission->provider) ?? __('Automated pipeline'))
                : ($log->admin?->name ?? __('An administrator')),
            'at' => $log->created_at?->format('d M Y, H:i'),
            /*
             * The code first, the free text second. Remarks were optional
             * long after the rejection notice began interpolating a reason,
             * so a ledger that printed only remarks printed blanks.
             */
            'reason'  => $log->reason_code?->label() ?? $log->remarks,
            'remarks' => $log->remarks,
        ];
    }

    /**
     * Public URL for a stored KYC document.
     *
     * Uploads land on the `public` disk, whose root is storage/app/public and
     * which the web server reaches through the storage symlink — so a stored
     * "files/2026/09/19/passport.jpg" lives at /storage/files/…, not at
     * /files/…. The admin built the second one, so no KYC document has ever
     * actually rendered here; every viewer showed a broken image.
     */
    public static function documentUrl(int $submissionId, int $index): string
    {
        return route('admin.kyc.document', [
            'submission' => $submissionId,
            'document'   => $index,
        ]);
    }

    /**
     * Is this stored value one of the submission's uploaded documents?
     *
     * Legacy uploads sit under `files/` on the public disk; everything since
     * the documents were made private sits under `kyc-documents/`. Both are
     * documents, and a reviewer has to see either.
     */
    public static function isDocumentPath(mixed $value): bool
    {
        return is_string($value)
            && (str_starts_with($value, 'files/') || str_starts_with($value, KycDocumentDisk::PREFIX.'/'));
    }

    /**
     * The uploaded documents on this submission.
     *
     * The `index` is what addresses a document over HTTP. It is deliberately
     * not the path: an endpoint that accepts a path accepts whatever path it
     * is given, and the first thing anyone tries is ../../.env. The reviewer
     * asks for "document 2 of submission 41" and the server looks up what
     * that is.
     *
     * @return list<array{index: int, label: string, path: string, url: string, is_image: bool}>
     */
    public function documents(): array
    {
        $documents = [];
        $index     = 0;

        foreach ($this->submission->submission_data ?? [] as $label => $value) {
            if (! self::isDocumentPath($value)) {
                continue;
            }

            $documents[] = [
                'index'    => $index,
                'label'    => self::humanise((string) $label),
                'path'     => $value,
                'url'      => self::documentUrl((int) $this->submission->id, $index),
                'is_image' => (bool) preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $value),
            ];

            $index++;
        }

        return $documents;
    }

    /**
     * What the applicant typed, minus anything the page already says.
     *
     * Two things were leaking through before: keys the seeder or an importer
     * attached that were never fields on the template (a bare "seeded 1" read
     * as a KYC answer), and fields the surrounding page already shows — the
     * name is in the header, the email and country are in the account card,
     * so printing them again is furniture, not information.
     *
     * @param  list<string>          $alreadyShown labels the page prints elsewhere
     * @return array<string, string>
     */
    public function statedDetails(array $alreadyShown = []): array
    {
        $declared = collect($this->submission->kycTemplate?->fields ?? [])
            ->pluck('label')
            ->filter()
            ->map(fn ($label): string => self::normalise((string) $label))
            ->all();

        $skip = array_map(self::normalise(...), $alreadyShown);

        $details = [];

        foreach ($this->submission->submission_data ?? [] as $label => $value) {
            $key = self::normalise((string) $label);

            if (is_string($value) && str_starts_with($value, 'files/')) {
                continue;
            }

            // Only what the template actually asked for, when it still says.
            if ($declared !== [] && ! in_array($key, $declared, true)) {
                continue;
            }

            if (in_array($key, $skip, true) || $value === null || $value === '') {
                continue;
            }

            $details[self::humanise((string) $label)] = is_scalar($value)
                ? (string) $value
                : json_encode($value);
        }

        return $details;
    }

    private static function normalise(string $label): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($label)));
    }

    private static function humanise(string $label): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $label));
    }
}
