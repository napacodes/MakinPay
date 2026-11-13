<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a KYC submission was turned down.
 *
 * This exists because the rejection notification has always interpolated a
 * `rejection_reason`, while the form request left `remarks` nullable — so a
 * rejection sent with no remarks emailed the applicant a template with an
 * empty reason and no way to know what to fix. A code makes the reason
 * mandatory, comparable across reviewers, and countable.
 *
 * Free-text remarks are kept alongside it: the code says which kind of
 * problem, the remarks say what this particular person should do about it.
 */
enum KycRejectionReason: string
{
    case Unreadable        = 'unreadable';
    case Expired           = 'expired';
    case FaceMismatch      = 'face_mismatch';
    case DetailsMismatch   = 'details_mismatch';
    case DuplicateIdentity = 'duplicate_identity';
    case SuspectedForgery  = 'suspected_forgery';
    case Incomplete        = 'incomplete';
    case Other             = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Unreadable        => __('Document unreadable'),
            self::Expired           => __('Document expired'),
            self::FaceMismatch      => __('Selfie does not match the document'),
            self::DetailsMismatch   => __('Details do not match the account'),
            self::DuplicateIdentity => __('Suspected duplicate identity'),
            self::SuspectedForgery  => __('Suspected forgery'),
            self::Incomplete        => __('Required documents missing'),
            self::Other             => __('Other'),
        };
    }

    /**
     * Whether the applicant can fix this by submitting again.
     *
     * A blurred photo is a retake; a suspected forgery is not something the
     * applicant should be invited to "try again" with.
     */
    public function invitesResubmission(): bool
    {
        return in_array($this, [
            self::Unreadable,
            self::Expired,
            self::FaceMismatch,
            self::Incomplete,
        ], true);
    }

    /**
     * @return array<string, string> value => label, for a select
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
