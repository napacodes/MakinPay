<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FeatureResource;
use App\Services\FeatureManager;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What this installation offers, and what this customer may reach.
 *
 * The app builds its navigation from this. Without it the menu would be
 * hardcoded in Dart, and an administrator switching a module off would leave
 * every installed copy of the app showing a screen that 404s - with no way to
 * correct it short of a store release.
 *
 * The answer is per role, and needs no special handling to be so:
 * FeatureManager::currentPanel() derives the panel from User::role rather than
 * from the URL, so an agent's token is answered with the agent panel's rules.
 */
class FeatureController extends Controller
{
    public function __construct(private readonly FeatureManager $features) {}

    public function index(Request $request): JsonResponse
    {
        // Every catalogued module, not only the enabled ones. An app that is
        // told nothing about a disabled module cannot tell it apart from one
        // this version of the app has never heard of.
        $features = $this->features->all()->values();

        return ApiResponse::ok([
            'panel'    => $this->features->currentPanel($request->user()),
            'features' => FeatureResource::collection($features),
        ]);
    }
}
