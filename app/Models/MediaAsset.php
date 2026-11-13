<?php

namespace App\Models;

use App\Support\ByteSize;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    protected $fillable = [
        'media_storage_provider_id',
        'uploaded_by_admin_id',
        'title',
        'alt_text',
        'caption',
        'original_name',
        'file_name',
        'extension',
        'mime_type',
        'aggregate_type',
        'disk',
        'driver',
        'visibility',
        'folder',
        'path',
        'url',
        'size',
        'width',
        'height',
        'metadata',
    ];

    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(MediaStorageProvider::class, 'media_storage_provider_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'uploaded_by_admin_id');
    }

    public function isImage(): bool
    {
        return $this->aggregate_type === 'image';
    }

    /**
     * Where this file is actually served from, right now.
     *
     * The `url` column is written once, at upload, from `asset()` — so it
     * carries whatever scheme and host the site had that day. Move the site,
     * put it behind TLS, or copy the database to another domain and every
     * local image 404s while the file sits on disk untouched. Local files are
     * therefore resolved against the current request instead of the column.
     *
     * A provider with its own `url` — an S3 bucket, a CDN — is the opposite
     * case: that host is deliberate, and is left alone.
     */
    public function publicUrl(): string
    {
        $isLocal = $this->driver === 'local' || $this->disk === 'public';

        if ($isLocal && blank($this->storageProvider?->url) && filled($this->path)) {
            return asset('storage/'.ltrim((string) $this->path, '/'));
        }

        return (string) $this->url;
    }

    /**
     * `Number::fileSize()` hard-requires ext-intl, which is absent on a good
     * share of shared hosts and took the whole media library down with a 500.
     * `ByteSize` carries the local fallback; the probe stays here so a test
     * can force that path.
     */
    public function humanSize(): string
    {
        return ByteSize::human((int) $this->size, 0, $this->intlIsAvailable());
    }

    protected function intlIsAvailable(): bool
    {
        return extension_loaded('intl');
    }

    public function displayTitle(): string
    {
        return $this->title ?: $this->original_name;
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size'     => 'integer',
            'width'    => 'integer',
            'height'   => 'integer',
        ];
    }
}
