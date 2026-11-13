<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\KycSubmitRequest;
use App\Http\Requests\Api\V1\StartHostedKycRequest;
use App\Models\KycSubmission;
use App\Models\KycTemplate;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Kyc\KycAutomationSettings;
use App\Services\Kyc\KycJourneyResolver;
use App\Services\Kyc\KycSubmissionService;
use App\Services\Media\MediaSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * KYC from the app.
 *
 * Withdrawals and merchant payments carry requires_kyc, so an app user who met
 * KYC_REQUIRED needed a way through it without the website. Nothing here decides
 * anything of its own: which journey is offered is KycJourneyResolver's answer,
 * the rules are the website's form requests, and the submission itself is
 * KycSubmissionService - the same steps the website takes.
 *
 * A provider journey finishes in a browser. The address handed back is the
 * provider's own capture page; its verdict arrives on the webhook, so the app
 * learns the outcome by asking show() again rather than from the redirect.
 */
class KycController extends Controller
{
    public function __construct(private readonly KycJourneyResolver $journey) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->state($request->user()));
    }

    /**
     * The forms this applicant may fill in, and the limits a document has to
     * meet - so the app can refuse an oversized photo before uploading it.
     *
     * None while a provider hosts the capture: it replaces the form outright,
     * exactly as on the website.
     */
    public function templates(Request $request): JsonResponse
    {
        $user = $request->user();

        $templates = $this->journey->hostedProviderFor($user)
            ? collect()
            : KycTemplate::active()->whereJsonContains('applicable_to', $user->role->value)->get();

        return ApiResponse::ok([
            'templates' => $templates->map(fn (KycTemplate $template): array => [
                'id'          => $template->id,
                'title'       => $template->title,
                'description' => $template->description,
                'fields'      => array_map(fn (array $field): array => [
                    'label' => (string) $field['label'],
                    'type'  => (string) ($field['type'] ?? 'text'),
                    // Stored by the builder as the string 'true'.
                    'required' => in_array($field['required'] ?? false, [true, 'true'], true),
                ], $template->fields ?? []),
            ])->values(),
            'upload' => [
                'types'  => MediaSettings::customerSafe(MediaSettings::identityDocumentExtensions()),
                'max_kb' => MediaSettings::effectiveMaxUploadSizeKb(),
            ],
        ]);
    }

    public function submit(KycSubmitRequest $request, KycSubmissionService $kyc): JsonResponse
    {
        $user = $request->user();

        $kyc->submit(
            $user,
            $request->kycTemplate(),
            (array) ($request->validated()['credentials'] ?? []),
            $request->validated('note'),
            $request->ip(),
        );

        return ApiResponse::created($this->state($user));
    }

    /**
     * The provider's capture page to open. A live session already open for this
     * applicant is handed back rather than a second one started.
     */
    public function startHosted(StartHostedKycRequest $request, KycSubmissionService $kyc): JsonResponse
    {
        return ApiResponse::ok([
            'url' => $kyc->startHosted($request->user(), route('user.settings.kyc.verify'), $request->ip()),
        ]);
    }

    /**
     * Where the applicant stands, and what they can do next.
     *
     * The status is a name, not the enum's stored integer: an app switching on
     * 0/1/2 breaks the day someone reorders the cases.
     *
     * The reason is given only for a rejection. The same column holds the
     * applicant's own note while the submission is pending, which is theirs,
     * not a verdict.
     *
     * @return array<string, mixed>
     */
    private function state(User $user): array
    {
        $submission = $user->kycSubmission()->first();
        $status     = $submission?->status;
        $provider   = $this->journey->hostedProviderFor($user);
        $consent    = KycAutomationSettings::requiresConsent();

        return [
            'status' => match ($status) {
                KycStatus::PENDING  => 'pending',
                KycStatus::APPROVED => 'approved',
                KycStatus::REJECTED => 'rejected',
                default             => 'none',
            },
            'submitted_at'        => $submission?->created_at?->toIso8601String(),
            'reviewed_at'         => in_array($status, [KycStatus::APPROVED, KycStatus::REJECTED], true) ? ($submission->verified_at ?? $submission->updated_at)?->toIso8601String() : null,
            'rejection_reason'    => $status     === KycStatus::REJECTED ? $submission->notes : null,
            'can_submit'          => $submission === null || $status === KycStatus::REJECTED,
            'journey'             => $provider ? 'hosted' : 'form',
            'hosted_provider'     => $provider?->providerName(),
            'resume_url'          => $submission?->hasLiveHostedSession() ? $submission->provider_session_url : null,
            'resume_started_at'   => $submission?->hasLiveHostedSession() ? $submission->updated_at?->toIso8601String() : null,
            'requires_consent'    => $consent,
            'consent_text'        => $consent ? KycAutomationSettings::consentText() : null,
            'template_title'      => $submission?->kycTemplate?->title,
            'document_expires_at' => $this->documentExpiry($submission),
            'review_hours'        => (int) setting('kyc_review_hours', 24),
            'unlocks'             => $this->unlocks($user),
        ];
    }

    /**
     * What a verified identity switches on for this customer: the enabled
     * modules whose rules ask for KYC, named as the catalog names them.
     *
     * @return list<string>
     */
    private function unlocks(User $user): array
    {
        $role    = $user->role?->value ?? 'user';
        $catalog = (array) config('feature_catalog.features', []);
        $enabled = app(FeatureManager::class)->enabled();

        return collect($catalog)
            ->filter(fn (array $definition, string $key): bool => $enabled->has($key)
                && (bool) data_get($definition, 'rules.requires_kyc', false)
                && (bool) data_get($definition, "panels.$role", true))
            ->map(fn (array $definition, string $key): string => (string) ($enabled->get($key)?->label ?? data_get($definition, 'label', $key)))
            ->values()
            ->all();
    }

    /**
     * The document's own expiry, when the form asked for one: the first
     * date-shaped answer whose field is named like an expiry.
     */
    private function documentExpiry(?KycSubmission $submission): ?string
    {
        foreach ((array) ($submission?->submission_data ?? []) as $label => $value) {
            if (! is_string($value) || ! preg_match('/expir/i', (string) $label)) {
                continue;
            }
            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
