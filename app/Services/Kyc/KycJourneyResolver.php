<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Contracts\Kyc\HostedKycSessionInterface;
use App\Models\KycTemplate;
use App\Models\User;

/**
 * Decides which verification journey an applicant gets.
 *
 * Two exist. The provider-hosted journey sends the applicant to the verification
 * partner, who captures the documents and returns a verdict on our webhook. The
 * template journey collects documents on our own form for a human to review.
 *
 * The hosted one is offered only when automation is switched on *and* an enabled
 * provider covers the applicant's country *and* that provider can host the
 * capture. Anything short of all three leaves the applicant on the template
 * form, which is the behaviour that predates hosted journeys.
 */
final class KycJourneyResolver
{
    public function __construct(private readonly KycProviderRegistry $registry) {}

    /**
     * The hosted driver for this applicant, or null when they belong on the
     * template form.
     */
    public function hostedProviderFor(User $user): ?HostedKycSessionInterface
    {
        if (! KycAutomationSettings::enabled()) {
            return null;
        }

        /*
         * A fourth condition, added with the builder's Routing control: every
         * form this applicant could fill in says a person must read it. The
         * hosted journey replaces our form outright, so offering it here would
         * route them past the very review the admin asked for.
         */
        if (! $this->hasAutomatableTemplate($user)) {
            return null;
        }

        return $this->registry->hostedForCountry($user->country);
    }

    /**
     * Whether any live template for this applicant's role allows a provider.
     *
     * No template at all is not a reason to withhold the hosted journey —
     * that is precisely the case it was built for.
     */
    private function hasAutomatableTemplate(User $user): bool
    {
        $templates = KycTemplate::query()
            ->active()
            ->whereJsonContains('applicable_to', $user->role->value)
            ->get(['id', 'routing']);

        return $templates->isEmpty()
            || $templates->contains(fn (KycTemplate $template): bool => ! $template->isManualOnly());
    }
}
