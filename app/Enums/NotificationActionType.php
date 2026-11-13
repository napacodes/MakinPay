<?php

namespace App\Enums;

enum NotificationActionType: string
{
    case CREATED   = 'created';
    case APPROVED  = 'approved';
    case REJECTED  = 'rejected';
    case COMPLETED = 'completed';
    case REQUESTED = 'requested';
    case FAILED    = 'failed';
    case LOGGED    = 'logged';

    /*
     * Something ran correctly and found work for a person to do. Every other
     * case answers "did it succeed?", which left a job that succeeded *and*
     * needs attention with nowhere honest to sit: the ledger audit reported
     * findings under FAILED, so a run the command deliberately returns SUCCESS
     * for arrived as a red "Failed" pill, and one event read "success" in the
     * background-task log and "failed" in the notification beside it.
     */
    case REVIEW = 'review';

    public function label(): string
    {
        return match ($this) {
            self::CREATED   => 'Created',
            self::APPROVED  => 'Approved',
            self::REJECTED  => 'Rejected',
            self::COMPLETED => 'Completed',
            self::REQUESTED => 'Requested',
            self::FAILED    => 'Failed',
            self::LOGGED    => 'Logged',
            self::REVIEW    => 'Review',
        };
    }

    public function class(): string
    {
        return match ($this) {
            self::APPROVED, self::COMPLETED => 'success',
            self::REJECTED, self::FAILED => 'danger',
            self::REVIEW => 'warning',
            self::CREATED, self::LOGGED, self::REQUESTED => 'primary',
        };
    }

    public static function getClass(string $status): string
    {
        return self::tryFrom($status)?->class() ?? 'info';
    }
}
