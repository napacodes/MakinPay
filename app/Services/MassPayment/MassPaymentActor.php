<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Models\Admin;
use App\Models\User;

/**
 * Who moved a mass payment batch, or one of its lines, as each
 * mass_payment_events row records it. `id` is the user's or admin's id, and
 * null for the system.
 */
readonly class MassPaymentActor
{
    public const TYPE_SYSTEM = 'system';

    public const TYPE_USER = 'user';

    public const TYPE_ADMIN = 'admin';

    public function __construct(
        public string $type,
        public ?int $id = null,
    ) {}

    public static function system(): self
    {
        return new self(self::TYPE_SYSTEM);
    }

    public static function user(User $user): self
    {
        return new self(self::TYPE_USER, (int) $user->getKey());
    }

    public static function admin(Admin $admin): self
    {
        return new self(self::TYPE_ADMIN, (int) $admin->getKey());
    }
}
