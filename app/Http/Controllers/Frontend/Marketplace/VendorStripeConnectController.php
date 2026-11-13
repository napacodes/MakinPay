<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend\Marketplace;

use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceSetting;
use App\Models\Vendor;
use App\Services\Payment\Stripe\StripeConnectService;
use Illuminate\Http\RedirectResponse;

/**
 * Vendor-facing Stripe Connect onboarding. Stripe hosts the whole KYC flow;
 * this controller only mints the hosted links and reconciles the vendor's
 * onboarding badge when they come back.
 */
class VendorStripeConnectController extends Controller
{
    public function __construct(private readonly StripeConnectService $connect) {}

    /**
     * Send the vendor to Stripe's hosted onboarding. Stripe also calls this
     * route as `refresh_url` when a link has expired, which mints a fresh one.
     */
    public function onboard(): RedirectResponse
    {
        $vendor = $this->vendorOrFail();

        try {
            $url = $this->connect->onboardingLink(
                vendor: $vendor,
                returnUrl: route('user.marketplace.stripe.return'),
                refreshUrl: route('user.marketplace.stripe.onboard'),
            );
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return redirect()->route('user.marketplace.apply');
        }

        return redirect()->away($url);
    }

    /**
     * Stripe's `return_url`. Reaching it does not by itself mean onboarding is
     * complete, so the account is re-read before the badge is shown.
     */
    public function callback(): RedirectResponse
    {
        $vendor = $this->vendorOrFail();

        $vendor = $this->connect->refreshStatus($vendor);

        if ($this->connect->isReady($vendor)) {
            notifyEvs('success', __('Stripe onboarding complete — you can now receive card payments.'));
        } else {
            notifyEvs('warning', __('Stripe still needs more information before you can receive payments.'));
        }

        return redirect()->route('user.marketplace.apply');
    }

    /**
     * Open the vendor's Stripe Express dashboard (payouts, balances).
     */
    public function dashboard(): RedirectResponse
    {
        $vendor = $this->vendorOrFail();

        try {
            $url = $this->connect->dashboardLink($vendor);
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return redirect()->route('user.marketplace.apply');
        }

        return redirect()->away($url);
    }

    private function vendorOrFail(): Vendor
    {
        abort_unless((bool) (MarketplaceSetting::current()?->enabled), 404);

        $vendor = auth()->user()->vendor;

        abort_unless($vendor instanceof Vendor, 404);

        return $vendor;
    }
}
