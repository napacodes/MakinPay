<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\OpenApiGenerator;
use Illuminate\Http\JsonResponse;

/**
 * The API's own description, served from the API.
 *
 * Public, and deliberately so. It lists the paths that exist and the guards
 * each one carries; every one of those paths is already discoverable by
 * anyone who installs the app, and none of them is reachable without a token.
 * Hiding the map while leaving the doors as they are buys nothing and costs
 * an integrator a day.
 *
 * Not wrapped in the API envelope: the consumer is a code generator, which
 * expects an OpenAPI document at the top level and nothing else.
 */
class OpenApiController extends Controller
{
    public function __invoke(OpenApiGenerator $generator): JsonResponse
    {
        return response()->json(
            $generator->generate(),
            options: JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }
}
