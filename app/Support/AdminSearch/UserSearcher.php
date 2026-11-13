<?php

declare(strict_types=1);

namespace App\Support\AdminSearch;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\AdminShell;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer accounts — users, merchants and agents alike, told apart by role.
 *
 * Matched on the things an operator has in front of them when they come
 * looking: an email from a ticket, a phone number from a call, a name, a
 * username, or the account id off a transaction. System accounts are not
 * customers and never appear.
 */
final class UserSearcher implements Searcher
{
    public const KEY = 'users';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Users');
    }

    public function permission(): ?string
    {
        return 'user-list';
    }

    /**
     * @return list<array{type: string, id: string, title: string, sub: string, url: string, icon: string|null, initials: string|null, badge: string|null, tone: string}>
     */
    public function search(string $query, int $limit): array
    {
        $needle = trim($query);

        if ($needle === '') {
            return [];
        }

        $like  = '%'.$needle.'%';
        $words = preg_split('/\s+/u', $needle) ?: [];

        $users = User::query()
            ->whereNull('system_account_type')
            ->where(static function (Builder $where) use ($needle, $like, $words): void {
                $where->where('email', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like);

                /* "rahim uddin": first word against the first name, last against the last. */
                if (count($words) >= 2) {
                    $where->orWhere(static function (Builder $both) use ($words): void {
                        $both->where('first_name', 'like', '%'.$words[0].'%')
                            ->where('last_name', 'like', '%'.$words[count($words) - 1].'%');
                    });
                }

                if (ctype_digit($needle)) {
                    $where->orWhere('id', (int) $needle);
                }
            })
            ->with('kycSubmission')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $users->map(static function (User $user): array {
            $role = $user->role instanceof UserRole ? $user->role : UserRole::tryFrom((string) $user->role);

            [$badge, $tone] = self::state($user);

            return [
                'type'  => 'user',
                'id'    => 'user:'.$user->id,
                'title' => $user->name,
                'sub'   => implode(' · ', array_filter([
                    (string) $user->email,
                    $role?->title(),
                    '#'.$user->id,
                ])),
                'url'      => route('admin.user.manage', $user->username),
                'icon'     => null,
                'initials' => AdminShell::initials($user->name),
                'badge'    => $badge,
                'tone'     => $tone,
            ];
        })->values()->all();
    }

    /**
     * The one fact about the account worth a badge: it is waiting on KYC, it
     * is suspended, or it is fine.
     *
     * @return array{0: string, 1: string}
     */
    private static function state(User $user): array
    {
        $status = $user->status instanceof UserStatus ? $user->status : UserStatus::tryFrom((int) $user->status);

        if ($status === UserStatus::INACTIVE) {
            return [__('Suspended'), 'danger'];
        }

        $kyc = $user->kycSubmission?->status;

        if ($kyc === KycStatus::PENDING) {
            return [__('KYC pending'), 'warn'];
        }

        return [__('Active'), 'success'];
    }
}
