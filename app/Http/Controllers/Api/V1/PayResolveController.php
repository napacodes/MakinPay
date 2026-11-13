<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\PayCodeResolver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Name what a scanned code points at, before the app decides which flow to
 * open. Read-only.
 */
class PayResolveController extends Controller
{
    public function __construct(private readonly PayCodeResolver $resolver) {}

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:2048']]);

        return ApiResponse::ok($this->resolver->resolve($validated['code']));
    }
}
