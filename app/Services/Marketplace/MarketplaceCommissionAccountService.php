<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\SystemAccountType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MarketplaceSetting;
use App\Models\MarketplaceSettingRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MarketplaceCommissionAccountService
{
    public function provisionAndActivate(?int $adminId): User
    {
        return DB::transaction(function () use ($adminId): User {
            $settings = MarketplaceSetting::query()->lockForUpdate()->first()
                ?? MarketplaceSetting::query()->create([]);

            $previousAccountId = $settings->commission_user_id;
            $previousAccount   = $previousAccountId
                ? User::query()->find($previousAccountId)
                : null;
            $account = User::query()
                ->where('system_account_type', SystemAccountType::MARKETPLACE_COMMISSION->value)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                $identity = Str::lower(Str::random(20));

                $account = User::query()->forceCreate([
                    'first_name'          => 'Marketplace',
                    'last_name'           => 'Commission',
                    'username'            => 'system_marketplace_'.$identity,
                    'email'               => 'marketplace-commission+'.$identity.'@system.invalid',
                    'email_verified_at'   => now(),
                    'password'            => Str::random(64),
                    'role'                => UserRole::USER,
                    'status'              => UserStatus::INACTIVE,
                    'system_account_type' => SystemAccountType::MARKETPLACE_COMMISSION,
                ]);
            }

            if ((int) $previousAccountId !== (int) $account->id) {
                $settings->update(['commission_user_id' => $account->id]);

                MarketplaceSettingRevision::query()->create([
                    'admin_id'                    => $adminId,
                    'action'                      => 'commission_account_activated',
                    'previous_commission_user_id' => $previousAccountId,
                    'new_commission_user_id'      => $account->id,
                    'metadata'                    => [
                        'system_account_type' => SystemAccountType::MARKETPLACE_COMMISSION->value,
                        'balance_migrated'    => false,
                        'previous_account'    => $previousAccount ? [
                            'id'       => $previousAccount->id,
                            'username' => $previousAccount->username,
                            'email'    => $previousAccount->email,
                        ] : null,
                        'new_account' => [
                            'id'       => $account->id,
                            'username' => $account->username,
                            'email'    => $account->email,
                        ],
                    ],
                ]);

                Log::notice('Marketplace commission account activated', [
                    'admin_id'                    => $adminId,
                    'previous_commission_user_id' => $previousAccountId,
                    'new_commission_user_id'      => $account->id,
                    'balance_migrated'            => false,
                ]);
            }

            return $account;
        }, 3);
    }
}
