<?php

declare(strict_types=1);

namespace App\Services\Addons;

use RuntimeException;

/**
 * An install, enable, update or uninstall that was refused or did not finish.
 * Carries every reason, written for the operator, so the admin screen can
 * list them instead of a single generic failure.
 */
final class AddonOperationFailed extends RuntimeException
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function because(string ...$reasons): self
    {
        return new self(array_values($reasons));
    }
}
