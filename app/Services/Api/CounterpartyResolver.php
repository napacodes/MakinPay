<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\TrxType;
use App\Models\Transaction;
use App\Models\User;

/**
 * Who the other side of a transfer was.
 *
 * A send, a receipt and a request are each two rows, one per customer,
 * joined by `trx_reference` - the other leg's numeric id for a send, its
 * trx_id for a request. This reads across that join once per row and
 * remembers the answer, because a list of a hundred transfers asks the same
 * question a hundred times and the people repeat.
 */
class CounterpartyResolver
{
    public const TYPES = [TrxType::SEND_MONEY, TrxType::RECEIVE_MONEY, TrxType::REQUEST_MONEY];

    /** @var array<string, array<string, mixed>|null> */
    private array $memo = [];

    /**
     * @return array{user_id: int, name: string, username: string|null, avatar: string|null, verified: bool}|null
     */
    public function for(Transaction $trx): ?array
    {
        if (! in_array($trx->trx_type, self::TYPES, true)) {
            return null;
        }

        $key = (string) $trx->trx_id;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $user = $this->otherLeg($trx)?->user;

        return $this->memo[$key] = $user === null ? null : self::describe($user);
    }

    /**
     * The other customer's id alone, for grouping.
     */
    public function userIdFor(Transaction $trx): ?int
    {
        return $this->for($trx)['user_id'] ?? null;
    }

    /**
     * @return array{user_id: int, name: string, username: string|null, avatar: string|null, verified: bool}
     */
    public static function describe(User $user): array
    {
        return [
            'user_id'  => (int) $user->id,
            'name'     => (string) $user->name,
            'username' => $user->username,
            'avatar'   => asset($user->avatar_alt),
            'verified' => $user->isKycVerified(),
        ];
    }

    private function otherLeg(Transaction $trx): ?Transaction
    {
        $reference = (string) ($trx->trx_reference ?? '');

        if ($reference === '') {
            return null;
        }

        $query = Transaction::query()->with('user');

        if ($trx->trx_type === TrxType::REQUEST_MONEY) {
            return $query->where('trx_id', $reference)->first();
        }

        return ctype_digit($reference) ? $query->whereKey((int) $reference)->first() : $query->where('trx_id', $reference)->first();
    }
}
