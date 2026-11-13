<?php

namespace App\Http\Resources\Backend;

use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One media file as the library screen needs it.
 *
 * The inspector saves without a reload and the drop zone inserts cards for
 * files the page has never seen, so both need exactly the fields the card and
 * the inspector render — including the already-formatted size and upload time,
 * because the browser has neither the ext-intl fallback `humanSize()` carries
 * nor the admin's locale for a relative date.
 *
 * @mixin MediaAsset
 */
class MediaAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MediaAsset $asset */
        $asset = $this->resource;

        return [
            'id'             => $asset->id,
            'title'          => $asset->title,
            'display_title'  => $asset->displayTitle(),
            'alt_text'       => $asset->alt_text,
            'caption'        => $asset->caption,
            'original_name'  => $asset->original_name,
            'extension'      => strtoupper((string) $asset->extension),
            'aggregate_type' => $asset->aggregate_type,
            'is_image'       => $asset->isImage(),
            'folder'         => $asset->folder,
            /* Resolved against this request — see MediaAsset::publicUrl(). */
            'url'         => $asset->publicUrl(),
            'size'        => (int) $asset->size,
            'size_human'  => $asset->humanSize(),
            'width'       => $asset->width,
            'height'      => $asset->height,
            'dimensions'  => $asset->width && $asset->height ? $asset->width.' × '.$asset->height : null,
            'provider'    => $asset->storageProvider?->name,
            'uploaded_at' => $asset->created_at?->diffForHumans(),
            'uploader'    => $asset->uploader?->name,
            'update_url'  => route('admin.media.update', $asset),
            'destroy_url' => route('admin.media.destroy', $asset),
        ];
    }
}
