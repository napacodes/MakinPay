<?php

namespace App\Enums;

enum KycReviewAction: string
{
    case Submitted       = 'submitted';
    case Resubmitted     = 'resubmitted';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case AutoApproved    = 'auto_approved';
    case AutoRejected    = 'auto_rejected';
    case RoutedToManual  = 'routed_to_manual';
    case ProviderPending = 'provider_pending';

    /**
     * Human-readable label for the review action.
     */
    public function label(): string
    {
        return match ($this) {
            self::Submitted       => __('Submitted'),
            self::Resubmitted     => __('Resubmitted'),
            self::Approved        => __('Approved'),
            self::Rejected        => __('Rejected'),
            self::AutoApproved    => __('Auto-approved'),
            self::AutoRejected    => __('Auto-rejected'),
            self::RoutedToManual  => __('Routed to manual review'),
            self::ProviderPending => __('Awaiting provider result'),
        };
    }

    /**
     * The colour the audit trail paints this step.
     *
     * The trail used to draw every dot in one grey, so a rejection and a
     * routing note read the same at a glance on a screen whose whole job is
     * telling them apart.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Approved, self::AutoApproved => 'success',
            self::Rejected, self::AutoRejected => 'danger',
            self::RoutedToManual  => 'warning',
            self::ProviderPending => 'info',
            self::Submitted, self::Resubmitted => 'primary',
        };
    }

    /**
     * Whether the action was taken by the automated pipeline (no admin actor).
     */
    public function isAutomated(): bool
    {
        return in_array($this, [
            self::AutoApproved,
            self::AutoRejected,
            self::RoutedToManual,
            self::ProviderPending,
        ], true);
    }
}
