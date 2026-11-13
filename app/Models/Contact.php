<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a customer's address book on the platform.
 *
 * @property int         $id
 * @property int         $user_id
 * @property int|null    $contact_user_id
 * @property string|null $nickname
 * @property string|null $display_name    the phone-book name, for a match not yet on the platform
 * @property string|null $phone_hash
 * @property string      $source          transactions, phonebook or manual
 * @property bool        $favorite
 * @property bool        $blocked
 */
class Contact extends Model
{
    public const SOURCE_TRANSACTIONS = 'transactions';

    public const SOURCE_PHONEBOOK = 'phonebook';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'user_id',
        'contact_user_id',
        'nickname',
        'display_name',
        'phone_hash',
        'source',
        'favorite',
        'blocked',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'favorite' => 'boolean',
            'blocked'  => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contactUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }

    /**
     * Whether [$ownerId] has blocked [$otherId] - the one question the
     * money services ask of this table.
     */
    public static function isBlocked(int $ownerId, int $otherId): bool
    {
        return static::query()
            ->where('user_id', $ownerId)
            ->where('contact_user_id', $otherId)
            ->where('blocked', true)
            ->exists();
    }
}
