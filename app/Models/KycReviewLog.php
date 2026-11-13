<?php

namespace App\Models;

use App\Enums\KycRejectionReason;
use App\Enums\KycReviewAction;
use App\Enums\KycStatus;
use Database\Factories\KycReviewLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycReviewLog extends Model
{
    /** @use HasFactory<KycReviewLogFactory> */
    use HasFactory;

    protected $fillable = [
        'kyc_submission_id',
        'admin_id',
        'action',
        'reason_code',
        'from_status',
        'to_status',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action'      => KycReviewAction::class,
            'reason_code' => KycRejectionReason::class,
            'from_status' => KycStatus::class,
            'to_status'   => KycStatus::class,
        ];
    }

    public function kycSubmission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
