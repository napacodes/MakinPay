<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The account, as the app is allowed to see it.
 *
 * Every key is listed here by hand rather than filtered out of the model. The
 * model's $hidden covers the password, the wallet PIN and the remember token
 * but not google2fa_secret, so a resource that started from the whole row and
 * removed what it did not want would hand out a working second factor the
 * first time somebody added a column. A whitelist cannot fail that way.
 *
 * `has_wallet_pin` is a boolean, never the PIN. The app needs to know whether
 * to show "set a PIN" or "enter your PIN", and that is the whole question.
 *
 * @property-read User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id'         => $user->id,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'username'   => $user->username,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'role'       => $user->role?->value,
            'avatar'     => asset($user->avatar_alt),
            'country'    => $user->country,
            // PH10: local or international by the site's home country, as
            // tax reads it; null when there is no home country or the
            // country is not one the list knows.
            'account_scope' => $user->accountScope(),

            // Presentation only: the unit totals and insights are shown in.
            // Null means the site base. What is stored never moves with it.
            'display_currency'        => $user->display_currency,
            'monthly_statement_email' => (bool) $user->monthly_statement_email,

            'status' => $user->status?->value ?? $user->status,

            // What the app has to branch on before it can offer an action.
            'email_verified'     => $user->email_verified_at !== null,
            'phone_verified'     => $user->phone_verified_at !== null,
            'kyc_verified'       => $user->isKycVerified(),
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'has_wallet_pin'     => $user->wallet_pin !== null,

            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
