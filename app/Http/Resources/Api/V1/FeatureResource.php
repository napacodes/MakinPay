<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Feature;
use App\Services\FeatureManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One module, as it stands for the caller asking.
 *
 * `enabled` is the site-wide switch; `accessible` is whether this particular
 * customer can use it right now. They are separate because the app needs both:
 * a module that is off should not appear at all, while one that is on but
 * blocked should appear and explain itself - "verify your identity" is a
 * screen worth showing, a silently missing menu item is not.
 *
 * `denial_reason` is one of FeatureManager's own constants, never a sentence.
 * The app decides what to say and in which language.
 *
 * @property-read Feature $resource
 */
class FeatureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $feature  = $this->resource;
        $features = app(FeatureManager::class);

        $reason = $features->denialReason($feature->key, null, $request->user());

        return [
            'key'           => $feature->key,
            'label'         => $feature->label,
            'category'      => $feature->category,
            'icon'          => $feature->icon,
            'enabled'       => $features->isEnabled($feature->key),
            'accessible'    => $reason === null,
            'denial_reason' => $reason,
        ];
    }
}
