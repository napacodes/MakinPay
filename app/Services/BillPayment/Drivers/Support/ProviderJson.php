<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Support;

use Illuminate\Http\Client\Response;
use JsonException;

/**
 * Decodes a provider reply without losing digits.
 *
 * `$response->json()` decodes a long integer as a float, so a 20-digit
 * prepaid meter token sent as a bare JSON number would come back rounded.
 * JSON_BIGINT_AS_STRING keeps such a number as a string with every digit.
 */
final class ProviderJson
{
    /**
     * @return array<array-key, mixed>|null null when the body is empty, not JSON, or not an object or list
     */
    public static function decode(Response $response): ?array
    {
        try {
            $decoded = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
