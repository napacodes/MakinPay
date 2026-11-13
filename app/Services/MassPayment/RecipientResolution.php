<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\MassPayment\MassPaymentIssue;
use App\Enums\MassPayment\MassPaymentIssueHint;
use App\Models\User;
use App\Models\Wallet;

/**
 * What one mass payment line's recipient resolved to.
 *
 * Either a recipient and the one wallet to credit, with no issue, or an issue
 * and neither. A refused line never carries the account it nearly matched, so
 * nothing downstream can pin, display or pay it by mistake.
 *
 * `label` is safe to send to a client: a first name and last initial with a
 * masked identifier for a resolved line, and only the masked identifier for a
 * refused one, so a refusal never confirms whose account an identifier is.
 *
 * `type` is the identifier kind detected from the typed text (`email`,
 * `mobile`, `wallet_id`), or null when it looked like none of them.
 */
readonly class RecipientResolution
{
    public function __construct(
        public ?User $user,
        public ?Wallet $wallet,
        public ?string $type,
        public string $label,
        public ?MassPaymentIssue $issue,
        public ?MassPaymentIssueHint $hint = null,
    ) {}

    public static function resolved(User $user, Wallet $wallet, string $type, string $label): self
    {
        return new self($user, $wallet, $type, $label, null);
    }

    public static function refused(MassPaymentIssue $issue, ?string $type, string $label, ?MassPaymentIssueHint $hint = null): self
    {
        return new self(null, null, $type, $label, $issue, $hint);
    }

    public function isResolved(): bool
    {
        return $this->issue === null && $this->user !== null && $this->wallet !== null;
    }

    /**
     * The sentence to show next to a refused line, or null for a resolved one.
     * A hint, when there is one, says more precisely what to type instead.
     */
    public function message(): ?string
    {
        if ($this->issue === null) {
            return null;
        }

        return $this->hint?->message() ?? $this->issue->message();
    }
}
