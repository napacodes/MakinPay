<?php

declare(strict_types=1);

namespace App\Services\Addons;

use RuntimeException;

/**
 * An addon.json that cannot be trusted. Carries every problem found, not just
 * the first, so an add-on author fixes the file in one pass.
 */
final class InvalidAddonManifest extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly string $location, public readonly array $errors)
    {
        parent::__construct(sprintf('Invalid add-on manifest %s: %s', $location, implode(' ', $errors)));
    }
}
