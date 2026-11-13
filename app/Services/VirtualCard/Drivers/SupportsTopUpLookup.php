<?php

declare(strict_types=1);

namespace App\Services\VirtualCard\Drivers;

use App\Models\VirtualCard;

/**
 * A driver that can be asked what became of a top-up it was sent.
 *
 * This is the only thing that turns an ambiguous outcome back into a decision.
 * Without it, a timed-out funding stays in needs_review until a human and the
 * provider settle it between them — which is the correct outcome, not a
 * limitation to work around.
 */
interface SupportsTopUpLookup
{
    /**
     * @return array{status: string, reference?: ?string, id?: ?string}|null
     *                                                                       null when the provider has no record of the reference,
     *                                                                       which is itself inconclusive unless the provider
     *                                                                       guarantees otherwise
     */
    public function findTopUpByReference(string $reference, VirtualCard $card): ?array;
}
