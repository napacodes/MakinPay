<?php

declare(strict_types=1);

namespace App\Services\Payment\Stripe;

use App\Exceptions\NotifyErrorException;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;

/**
 * Owns the Stripe Connect account lifecycle for marketplace vendors: creating
 * the connected account, handing the vendor Stripe's hosted onboarding, and
 * keeping `vendors.stripe_onboarding_status` in step with Stripe's view.
 *
 * The account id lives on the vendor row (`stripe_connect_account_id`), which
 * has been part of the vendors schema since the marketplace shipped.
 */
class StripeConnectService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESTRICTED = 'restricted';

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    private readonly StripeCredentials $credentials;

    public function __construct(?StripeCredentials $credentials = null)
    {
        $this->credentials = $credentials ?? StripeCredentials::fromGateway();
    }

    public function isEnabled(): bool
    {
        return $this->credentials->connectEnabled() && $this->credentials->secretKey() !== '';
    }

    /**
     * A vendor can only receive a Connect-settled payment once Stripe has
     * cleared the account for both charges and payouts.
     */
    public function isReady(Vendor $vendor): bool
    {
        return filled($vendor->stripe_connect_account_id)
            && $vendor->stripe_onboarding_status === self::STATUS_ENABLED;
    }

    /**
     * Return the vendor's connected account id, creating the account on the
     * first call. Safe to call repeatedly.
     *
     * @throws NotifyErrorException
     */
    public function ensureAccount(Vendor $vendor): string
    {
        if (filled($vendor->stripe_connect_account_id)) {
            return (string) $vendor->stripe_connect_account_id;
        }

        $this->guardEnabled();

        try {
            $account = $this->client()->accounts->create($this->accountPayload($vendor));
        } catch (ApiErrorException $e) {
            Log::error('Stripe Connect account creation failed', [
                'vendor_id' => $vendor->id,
                'error'     => $e->getMessage(),
            ]);

            throw new NotifyErrorException(__('Could not start Stripe onboarding: :msg', ['msg' => $e->getMessage()]));
        }

        $vendor->forceFill([
            'stripe_connect_account_id' => $account->id,
            'stripe_onboarding_status'  => self::STATUS_PENDING,
        ])->save();

        return (string) $account->id;
    }

    /**
     * Hosted onboarding URL. Stripe expires these links quickly, so one is
     * minted per click rather than stored.
     *
     * @throws NotifyErrorException
     */
    public function onboardingLink(Vendor $vendor, string $returnUrl, string $refreshUrl): string
    {
        $accountId = $this->ensureAccount($vendor);

        try {
            $link = $this->client()->accountLinks->create([
                'account'     => $accountId,
                'refresh_url' => $refreshUrl,
                'return_url'  => $returnUrl,
                'type'        => 'account_onboarding',
            ]);
        } catch (ApiErrorException $e) {
            throw new NotifyErrorException(__('Could not start Stripe onboarding: :msg', ['msg' => $e->getMessage()]));
        }

        return (string) $link->url;
    }

    /**
     * Express dashboard link. Standard accounts own their Stripe dashboard, so
     * Stripe refuses to mint a login link for them.
     *
     * @throws NotifyErrorException
     */
    public function dashboardLink(Vendor $vendor): string
    {
        if (! filled($vendor->stripe_connect_account_id)) {
            throw new NotifyErrorException(__('Complete Stripe onboarding first.'));
        }

        if ($this->credentials->connectAccountType() !== 'express') {
            throw new NotifyErrorException(__('The Stripe dashboard is only available for Express accounts.'));
        }

        try {
            $link = $this->client()->accounts->createLoginLink((string) $vendor->stripe_connect_account_id);
        } catch (ApiErrorException $e) {
            throw new NotifyErrorException(__('Could not open the Stripe dashboard: :msg', ['msg' => $e->getMessage()]));
        }

        return (string) $link->url;
    }

    /**
     * Pull the account from Stripe and persist the derived status.
     */
    public function refreshStatus(Vendor $vendor): Vendor
    {
        if (! filled($vendor->stripe_connect_account_id)) {
            return $vendor;
        }

        try {
            $account = $this->client()->accounts->retrieve((string) $vendor->stripe_connect_account_id);
        } catch (Throwable $e) {
            Log::warning('Stripe Connect status refresh failed', [
                'vendor_id' => $vendor->id,
                'error'     => $e->getMessage(),
            ]);

            return $vendor;
        }

        return $this->applyAccountStatus($vendor, $this->toArray($account));
    }

    /**
     * Persist the status derived from an account payload — used by both the
     * manual refresh and the `account.updated` webhook.
     *
     * @param array<string, mixed> $account
     */
    public function applyAccountStatus(Vendor $vendor, array $account): Vendor
    {
        $status = $this->deriveStatus($account);

        if ($vendor->stripe_onboarding_status !== $status) {
            $vendor->forceFill(['stripe_onboarding_status' => $status])->save();
        }

        return $vendor;
    }

    /**
     * Map Stripe's capability flags onto the four states the vendor UI shows.
     *
     * @param array<string, mixed> $account
     */
    public function deriveStatus(array $account): string
    {
        if (filled(data_get($account, 'requirements.disabled_reason'))) {
            return self::STATUS_DISABLED;
        }

        $charges = (bool) data_get($account, 'charges_enabled', false);
        $payouts = (bool) data_get($account, 'payouts_enabled', false);

        if ($charges && $payouts) {
            return self::STATUS_ENABLED;
        }

        return data_get($account, 'details_submitted', false)
            ? self::STATUS_RESTRICTED
            : self::STATUS_PENDING;
    }

    public function client(): StripeClient
    {
        $secret = $this->credentials->secretKey();

        if ($secret === '') {
            throw new NotifyErrorException(__('Stripe is not configured.'));
        }

        return new StripeClient($secret);
    }

    /**
     * Prefill what we already hold about the vendor so Stripe asks the
     * merchant for as little as possible.
     *
     * @return array<string, mixed>
     */
    private function accountPayload(Vendor $vendor): array
    {
        $vendor->loadMissing('user');
        $user = $vendor->user;

        $payload = [
            'type'         => $this->credentials->connectAccountType(),
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers'     => ['requested' => true],
            ],
            'business_profile' => array_filter([
                'name'                => $vendor->display_name,
                'product_description' => $vendor->about,
            ]),
            'metadata' => [
                'vendor_id' => (string) $vendor->id,
                'user_id'   => (string) $vendor->user_id,
            ],
        ];

        if (filled($user?->email)) {
            $payload['email'] = $user->email;
        }

        if (filled($user?->country)) {
            $payload['country'] = strtoupper((string) $user->country);
        }

        return $payload;
    }

    private function guardEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new NotifyErrorException(__('Stripe Connect is not enabled.'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return is_array($value) ? $value : [];
    }
}
