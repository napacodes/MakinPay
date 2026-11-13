<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement in or out of a vault, tied to the wallet transaction that
 * moved the money.
 */
class VaultEntry extends Model
{
    public const KINDS = ['deposit', 'withdraw', 'auto_save', 'round_up', 'close'];

    protected $fillable = ['vault_id', 'user_id', 'kind', 'amount', 'currency', 'transaction_id', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8'];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isCredit(): bool
    {
        return in_array($this->kind, ['deposit', 'auto_save', 'round_up'], true);
    }
}
