<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Storage-only stand-in for the web push package's subscription model.
 *
 * DigiKash ships source updates that customers apply before running Composer,
 * so the notification package can legitimately be absent for a window. Keeping
 * a first-party model on the same table lets subscriptions keep recording; the
 * package's own model takes over again as soon as it is installed.
 *
 * @property int|string   $subscribable_id
 * @property class-string $subscribable_type
 * @property string       $endpoint
 * @property string|null  $public_key
 * @property string|null  $auth_token
 * @property string|null  $content_encoding
 */
class PushSubscription extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
    ];

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        if ($this->connection === null) {
            $this->setConnection(config('webpush.database_connection'));
        }

        if ($this->table === null) {
            $this->setTable(config('webpush.table_name'));
        }

        parent::__construct($attributes);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }
}
