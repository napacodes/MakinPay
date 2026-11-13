<?php

namespace App\Enums\Api;

use Symfony\Component\HttpFoundation\Response;

/**
 * Stable, machine-readable failure codes for the mobile API.
 *
 * The Flutter client branches on these, never on the message: the message is
 * translated and is free to be reworded, while a `code` is a contract. A code
 * that ships can be added to but never renamed, because an installed app keeps
 * comparing against the string it was built with.
 *
 * Each case carries its own HTTP status so a controller cannot pair
 * KYC_REQUIRED with a 500 in one place and a 403 in another.
 */
enum ApiErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';

    case Unauthenticated = 'UNAUTHENTICATED';

    case TokenExpired = 'TOKEN_EXPIRED';

    case Forbidden = 'FORBIDDEN';

    case AccountSuspended = 'ACCOUNT_SUSPENDED';

    case EmailUnverified = 'EMAIL_UNVERIFIED';

    case PasswordChangeRequired = 'PASSWORD_CHANGE_REQUIRED';

    case TwoFactorRequired = 'TWO_FACTOR_REQUIRED';

    case KycRequired = 'KYC_REQUIRED';

    case PhoneVerificationRequired = 'PHONE_VERIFICATION_REQUIRED';

    case CountryNotSupported = 'COUNTRY_NOT_SUPPORTED';

    case WalletPinRequired = 'WALLET_PIN_REQUIRED';

    case WalletPinInvalid = 'WALLET_PIN_INVALID';

    case InsufficientBalance = 'INSUFFICIENT_BALANCE';

    case FeatureDisabled = 'FEATURE_DISABLED';

    case FeatureNotAccessible = 'FEATURE_NOT_ACCESSIBLE';

    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';

    case RateLimited = 'RATE_LIMITED';

    case MaintenanceMode = 'MAINTENANCE_MODE';

    case UpgradeRequired = 'UPGRADE_REQUIRED';

    case ResourceNotFound = 'RESOURCE_NOT_FOUND';

    case ServerError = 'SERVER_ERROR';

    case TemporarilyUnavailable = 'TEMPORARILY_UNAVAILABLE';

    /**
     * The mass payment being confirmed is not the one the customer reviewed:
     * its lines priced or resolved differently, or the fingerprint or total
     * sent is stale. Nothing was debited; the refusal carries the batch as it
     * now stands so the app can show the refreshed preview.
     */
    case MassPaymentChanged = 'MASS_PAYMENT_CHANGED';

    /**
     * The mass payment cannot be confirmed as it stands. Nothing was debited;
     * the refusal carries a stable `reason` the app branches on.
     */
    case MassPaymentNotConfirmable = 'MASS_PAYMENT_NOT_CONFIRMABLE';

    /**
     * The withdraw method a mass payout names cannot be used from the wallet,
     * at draft or at confirm. Nothing was written or debited; the app shows
     * the methods it may use instead.
     */
    case MassPayoutMethodUnavailable = 'MASS_PAYOUT_METHOD_UNAVAILABLE';

    /**
     * The remittance quote a transfer was sent against can no longer be used:
     * its rate hold ran out, or it already paid for a transfer. Nothing was
     * debited. The app fetches a fresh quote and shows the customer the new
     * rate before asking again - a 409 rather than a 422 because nothing the
     * customer typed is wrong; the world moved under it.
     */
    case QuoteExpired = 'QUOTE_EXPIRED';

    /**
     * The HTTP status this failure is always reported with.
     */
    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::Unauthenticated,
            self::TokenExpired => Response::HTTP_UNAUTHORIZED,
            self::Forbidden,
            self::AccountSuspended,
            self::EmailUnverified,
            self::PasswordChangeRequired,
            self::TwoFactorRequired,
            self::KycRequired,
            self::PhoneVerificationRequired,
            self::CountryNotSupported,
            self::WalletPinRequired,
            self::WalletPinInvalid,
            self::FeatureDisabled,
            self::FeatureNotAccessible   => Response::HTTP_FORBIDDEN,
            self::InsufficientBalance    => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::IdempotencyConflict    => Response::HTTP_CONFLICT,
            self::RateLimited            => Response::HTTP_TOO_MANY_REQUESTS,
            self::MaintenanceMode        => Response::HTTP_SERVICE_UNAVAILABLE,
            self::UpgradeRequired        => Response::HTTP_UPGRADE_REQUIRED,
            self::ResourceNotFound       => Response::HTTP_NOT_FOUND,
            self::ServerError            => Response::HTTP_INTERNAL_SERVER_ERROR,
            self::TemporarilyUnavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            self::MassPaymentChanged,
            self::MassPaymentNotConfirmable   => Response::HTTP_CONFLICT,
            self::MassPayoutMethodUnavailable => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::QuoteExpired                => Response::HTTP_CONFLICT,
        };
    }

    /**
     * The message sent when a caller supplies none.
     *
     * Deliberately non-specific for the authentication and lookup cases: a
     * reply that distinguishes "no such account" from "wrong password", or
     * "not yours" from "does not exist", tells an attacker which guess was
     * closer.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::ValidationFailed            => __('The given data was invalid.'),
            self::Unauthenticated             => __('Unauthenticated.'),
            self::TokenExpired                => __('Your session has expired. Please sign in again.'),
            self::Forbidden                   => __('This action is unauthorized.'),
            self::AccountSuspended            => __('This account is not active.'),
            self::EmailUnverified             => __('Please verify your email address first.'),
            self::PasswordChangeRequired      => __('You must change your password before continuing.'),
            self::TwoFactorRequired           => __('Two-factor authentication is required.'),
            self::KycRequired                 => __('Please complete your KYC verification to access this feature.'),
            self::PhoneVerificationRequired   => __('Please enable phone verification to access this feature.'),
            self::CountryNotSupported         => __('This feature is not available in your country.'),
            self::WalletPinRequired           => __('Your wallet PIN is required for this action.'),
            self::WalletPinInvalid            => __('The wallet PIN is incorrect.'),
            self::InsufficientBalance         => __('Insufficient balance.'),
            self::FeatureDisabled             => __('This feature is not available.'),
            self::FeatureNotAccessible        => __('You cannot use this feature right now.'),
            self::IdempotencyConflict         => __('This request was already submitted with different data.'),
            self::RateLimited                 => __('Too many requests. Please slow down.'),
            self::MaintenanceMode             => __('The service is temporarily unavailable for maintenance.'),
            self::UpgradeRequired             => __('Please update the app to continue.'),
            self::ResourceNotFound            => __('Not found.'),
            self::ServerError                 => __('Something went wrong. Please try again.'),
            self::TemporarilyUnavailable      => __('This is not available right now. Please try again later.'),
            self::MassPaymentChanged          => __('The batch changed since you reviewed it. Review it again.'),
            self::MassPaymentNotConfirmable   => __('This batch cannot be confirmed.'),
            self::MassPayoutMethodUnavailable => __('This payout method is not available for mass payouts from this wallet.'),
            self::QuoteExpired                => __('This rate is no longer held. Get a fresh quote and try again.'),
        };
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
