<?php

declare(strict_types=1);

namespace App\Enums\MassPayment;

/**
 * Why a mass payment line cannot be paid, or why it was not.
 *
 * The value is the stable code stored in `mass_payment_rows.issue_code`, sent
 * to API clients and written into the report. It never changes once shipped
 * and is never translated; {@see self::message()} is the translated text.
 *
 * Draft-time codes are found while the preview is built or re-checked at
 * confirm. Pay-time codes are written when a reserved line is refunded or
 * closed.
 *
 * The second group of each kind (from MethodUnavailable, and from
 * ProviderNotSent) belongs to payout batches only: a wallet line never
 * carries one.
 */
enum MassPaymentIssue: string
{
    case InvalidIdentifier    = 'invalid_identifier';
    case MethodNotAllowed     = 'method_not_allowed';
    case UnknownRecipient     = 'unknown_recipient';
    case AmbiguousRecipient   = 'ambiguous_recipient';
    case RecipientUnverified  = 'recipient_unverified';
    case WrongCurrency        = 'wrong_currency';
    case NoWalletInCurrency   = 'no_wallet_in_currency';
    case OwnWallet            = 'own_wallet';
    case RecipientUnavailable = 'recipient_unavailable';
    case WalletUnavailable    = 'wallet_unavailable';
    case InvalidAmount        = 'invalid_amount';
    case TooManyDecimals      = 'too_many_decimals';
    case BelowLimit           = 'below_limit';
    case AboveLimit           = 'above_limit';
    case NoteTooLong          = 'note_too_long';
    case RecipientChanged     = 'recipient_changed';

    case Stopped           = 'stopped';
    case CancelledByAdmin  = 'cancelled_by_admin';
    case ProcessingError   = 'processing_error';
    case ClosedAfterReview = 'closed_after_review';

    case MethodUnavailable    = 'method_unavailable';
    case AccountFieldMissing  = 'account_field_missing';
    case AccountFieldInvalid  = 'account_field_invalid';
    case FeeExceedsAmount     = 'fee_exceeds_amount';
    case AmountNotPayable     = 'amount_not_payable';
    case BelowProviderLimit   = 'below_provider_limit';
    case AboveProviderLimit   = 'above_provider_limit';
    case PrecisionUnsupported = 'precision_unsupported';

    case ProviderNotSent  = 'provider_not_sent';
    case ProviderRejected = 'provider_rejected';
    case PayoutRejected   = 'payout_rejected';
    case RejectedByAdmin  = 'rejected_by_admin';
    case ConfirmedNotPaid = 'confirmed_not_paid';

    /** The sentence shown next to the line in the preview, the API and the report. */
    public function message(): string
    {
        return match ($this) {
            self::InvalidIdentifier    => __('This is not a valid email address, mobile number or wallet ID.'),
            self::MethodNotAllowed     => __('Paying by this kind of identifier is not allowed.'),
            self::UnknownRecipient     => __('No eligible account was found for this recipient.'),
            self::AmbiguousRecipient   => __('This matches more than one account or wallet. Use their wallet ID.'),
            self::RecipientUnverified  => __('This recipient has not verified the identifier you used.'),
            self::WrongCurrency        => __('This wallet is not in the currency of the source wallet.'),
            self::NoWalletInCurrency   => __('This recipient has no wallet in this currency.'),
            self::OwnWallet            => __('You cannot pay your own account.'),
            self::RecipientUnavailable => __('This recipient cannot receive payments right now.'),
            self::WalletUnavailable    => __('This recipient\'s wallet cannot receive payments right now.'),
            self::InvalidAmount        => __('Enter the amount as a positive number, without separators or signs.'),
            self::TooManyDecimals      => __('The amount has more decimal places than this currency allows.'),
            self::BelowLimit           => __('The amount is below the minimum allowed per payment.'),
            self::AboveLimit           => __('The amount is above the maximum allowed per payment.'),
            self::NoteTooLong          => __('The note may be at most 255 characters.'),
            self::RecipientChanged     => __('This recipient now matches a different wallet. Review the batch again.'),
            self::Stopped              => __('Stopped before it was paid. The amount was refunded.'),
            self::CancelledByAdmin     => __('Cancelled by an administrator. The amount was refunded.'),
            self::ProcessingError      => __('This payment could not be processed. The amount was refunded.'),
            self::ClosedAfterReview    => __('Closed by an administrator after review.'),
            self::MethodUnavailable    => __('This payout method is not available for mass payouts.'),
            self::AccountFieldMissing  => __('A required account detail is missing.'),
            self::AccountFieldInvalid  => __('An account detail is not valid for this payout method.'),
            self::FeeExceedsAmount     => __('The fee is more than the amount, so nothing would be paid out.'),
            self::AmountNotPayable     => __('This amount cannot be paid out through this method.'),
            self::BelowProviderLimit   => __('The amount after fees is below the minimum the provider pays out.'),
            self::AboveProviderLimit   => __('The amount after fees is above the maximum the provider pays out.'),
            self::PrecisionUnsupported => __('This method cannot pay an amount with this many decimal places.'),
            self::ProviderNotSent      => __('The payout provider did not accept this payment. The amount was refunded.'),
            self::ProviderRejected     => __('The payout provider rejected this payment. The amount was refunded.'),
            self::PayoutRejected       => __('An operator could not complete this payout. The amount was refunded.'),
            self::RejectedByAdmin      => __('The batch was not approved. The amount was refunded.'),
            self::ConfirmedNotPaid     => __('An administrator confirmed this was not paid. The amount was refunded.'),
        };
    }

    /** A short name for filters and badges. */
    public function label(): string
    {
        return match ($this) {
            self::InvalidIdentifier    => __('Invalid identifier'),
            self::MethodNotAllowed     => __('Method not allowed'),
            self::UnknownRecipient     => __('Unknown recipient'),
            self::AmbiguousRecipient   => __('Ambiguous recipient'),
            self::RecipientUnverified  => __('Recipient unverified'),
            self::WrongCurrency        => __('Wrong currency'),
            self::NoWalletInCurrency   => __('No wallet in currency'),
            self::OwnWallet            => __('Own account'),
            self::RecipientUnavailable => __('Recipient unavailable'),
            self::WalletUnavailable    => __('Wallet unavailable'),
            self::InvalidAmount        => __('Invalid amount'),
            self::TooManyDecimals      => __('Too many decimals'),
            self::BelowLimit           => __('Below limit'),
            self::AboveLimit           => __('Above limit'),
            self::NoteTooLong          => __('Note too long'),
            self::RecipientChanged     => __('Recipient changed'),
            self::Stopped              => __('Stopped'),
            self::CancelledByAdmin     => __('Cancelled by admin'),
            self::ProcessingError      => __('Processing error'),
            self::ClosedAfterReview    => __('Closed after review'),
            self::MethodUnavailable    => __('Method unavailable'),
            self::AccountFieldMissing  => __('Account detail missing'),
            self::AccountFieldInvalid  => __('Account detail invalid'),
            self::FeeExceedsAmount     => __('Fee exceeds amount'),
            self::AmountNotPayable     => __('Amount not payable'),
            self::BelowProviderLimit   => __('Below provider limit'),
            self::AboveProviderLimit   => __('Above provider limit'),
            self::PrecisionUnsupported => __('Precision unsupported'),
            self::ProviderNotSent      => __('Not sent by provider'),
            self::ProviderRejected     => __('Rejected by provider'),
            self::PayoutRejected       => __('Rejected by operator'),
            self::RejectedByAdmin      => __('Rejected by admin'),
            self::ConfirmedNotPaid     => __('Confirmed not paid'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Stopped, self::CancelledByAdmin, self::ClosedAfterReview, self::RejectedByAdmin => 'secondary',
            self::RecipientChanged => 'warning',
            default                => 'danger',
        };
    }

    /** Written when a reserved line is refunded or closed, never at draft time. */
    public function isPayTime(): bool
    {
        return in_array($this, [
            self::Stopped,
            self::CancelledByAdmin,
            self::ProcessingError,
            self::ClosedAfterReview,
            self::ProviderNotSent,
            self::ProviderRejected,
            self::PayoutRejected,
            self::RejectedByAdmin,
            self::ConfirmedNotPaid,
        ], true);
    }
}
