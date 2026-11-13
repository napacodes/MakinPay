<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-way digest of one uploaded KYC document.
 *
 * It exists to answer a single question — has this exact file already been
 * submitted under a different account — without keeping a second copy of the
 * document anywhere. The digest cannot be turned back into the file.
 *
 * @property int    $kyc_submission_id
 * @property int    $user_id
 * @property string $field_label
 * @property string $hash
 */
class KycDocumentHash extends Model
{
    protected $fillable = [
        'kyc_submission_id',
        'user_id',
        'field_label',
        'hash',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
