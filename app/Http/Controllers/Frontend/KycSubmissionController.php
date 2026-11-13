<?php

namespace App\Http\Controllers\Frontend;

use App\Exceptions\KycSubmissionFailed;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\KycSubmitRequest;
use App\Http\Requests\Frontend\StartHostedKycRequest;
use App\Models\KycTemplate;
use App\Services\Kyc\KycAutomationSettings;
use App\Services\Kyc\KycJourneyResolver;
use App\Services\Kyc\KycSubmissionService;

class KycSubmissionController extends Controller
{
    public function kycVerify(KycJourneyResolver $journey)
    {
        $user = auth()->user();

        // A provider that can host the capture replaces our form outright, so
        // there is no point loading template options the applicant will never
        // be shown.
        $hostedProvider = $journey->hostedProviderFor($user);

        $kycTemplates = $hostedProvider
            ? collect()
            : KycTemplate::where('status', true)
                ->whereJsonContains('applicable_to', $user->role->value)
                ->get();

        return view('frontend.user.setting.kyc_verify', [
            'kycTemplates'       => $kycTemplates,
            'hostedProviderName' => $hostedProvider?->providerName(),
            'requiresConsent'    => KycAutomationSettings::requiresConsent(),
            'consentText'        => KycAutomationSettings::consentText(),
        ]);
    }

    /**
     * Hand the applicant over to the provider's own capture flow.
     *
     * The steps are KycSubmissionService's, shared with the app.
     *
     * @throws NotifyErrorException
     */
    public function startHosted(StartHostedKycRequest $request, KycSubmissionService $kyc)
    {
        return redirect()->away(
            $kyc->startHosted($request->user(), route('user.settings.kyc.verify'), $request->ip())
        );
    }

    public function templateDetails($id)
    {
        $template = KycTemplate::findOrFail($id);

        return view('frontend.user.setting.partials._kyc_template_details', compact('template'))->render();
    }

    /**
     * Submit the template form.
     *
     * The steps are KycSubmissionService's, shared with the app. A refusal
     * arrives as a NotifyErrorException and is flashed as it always was; a
     * fault arrives as KycSubmissionFailed, whose message is the one safe
     * sentence rather than the driver error this used to show.
     *
     * @throws NotifyErrorException
     */
    public function kycSubmit(KycSubmitRequest $request, KycSubmissionService $kyc)
    {
        try {
            $kyc->submit(
                $request->user(),
                $request->kycTemplate(),
                (array) ($request->validated()['credentials'] ?? []),
                $request->validated('note'),
                $request->ip(),
            );
        } catch (KycSubmissionFailed $fault) {
            throw new NotifyErrorException($fault->getMessage());
        }

        notifyEvs('success', __('KYC submission received successfully!'));

        return redirect()->back();
    }
}
