<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Enums\BillPayment\BillPaymentActorType;
use App\Models\Admin;
use App\Models\User;

/**
 * Who moved a payment from one state to the next, as each
 * bill_payment_events row records it. `id` is the user's or admin's id, and
 * null for the system and for a provider.
 */
readonly class BillPaymentActor
{
    public function __construct(
        public BillPaymentActorType $type,
        public ?int $id = null,
    ) {}

    public static function system(): self
    {
        return new self(BillPaymentActorType::System);
    }

    public static function user(User $user): self
    {
        return new self(BillPaymentActorType::User, (int) $user->getKey());
    }

    public static function admin(Admin $admin): self
    {
        return new self(BillPaymentActorType::Admin, (int) $admin->getKey());
    }

    public static function provider(): self
    {
        return new self(BillPaymentActorType::Provider);
    }
}
