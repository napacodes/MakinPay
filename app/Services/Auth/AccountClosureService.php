<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AgentStatus;
use App\Enums\MerchantStatus;
use App\Enums\P2P\OrderStatus;
use App\Enums\TrxStatus;
use App\Enums\UserStatus;
use App\Enums\VirtualCard\VirtualCardStatus;
use App\Enums\WalletEarnStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\P2P\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserPushToken;
use App\Models\Vault;
use App\Models\VirtualCard;
use App\Models\Wallet;
use App\Models\WalletEarnStake;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A customer closing their own account - the website's security screen and
 * the app alike, so the two cannot differ. The app stores require it.
 *
 * Refused while the account still holds anything: money in a wallet or a
 * savings vault, a transaction not yet settled, an open P2P trade or dispute, an approved
 * merchant or agent profile, a live virtual card or a running stake. Each is
 * named, so the customer knows what to finish first.
 *
 * Closing never deletes the row. The ledger, the KYC records and every
 * counterparty's history point at it, so the personal fields are wiped
 * instead: name, email, phone, address, avatar, second factor and PIN. The
 * password is replaced with one nobody knows, the wallets are switched off so
 * nothing can be sent in, and every way back in - app tokens, push tokens,
 * web-push subscriptions, browser sessions - is revoked.
 */
class AccountClosureService
{
    private ?bool $hasVaults = null;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * What stands between this account and closing it, keyed for the app
     * and worded for the customer. Empty when it may close.
     *
     * @return array<string, string>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        if (Wallet::query()->where('user_id', $user->id)->where('balance', '>', 0)->exists()) {
            $blockers['balance'] = __('Withdraw or spend the money in your wallets first.');
        }

        // Vault money left the wallets, so the check above cannot see it - and
        // once the wallets are switched off, nothing could bring it back.
        if ($this->hasVaults() && Vault::query()->where('user_id', $user->id)->where('balance', '>', 0)->exists()) {
            $blockers['vault_balance'] = __('Move the money in your savings vaults back to your wallets first.');
        }

        if (Transaction::query()->where('user_id', $user->id)->where('status', TrxStatus::PENDING)->exists()) {
            $blockers['pending_transaction'] = __('Wait for your pending transactions to finish.');
        }

        $openTrades = Order::query()
            ->where(fn ($query) => $query->where('maker_id', $user->id)->orWhere('taker_id', $user->id))
            ->whereIn('status', [OrderStatus::PENDING, OrderStatus::PAID, OrderStatus::DISPUTED])
            ->exists();

        if ($openTrades) {
            $blockers['open_p2p_order'] = __('Finish or cancel your open P2P trades and disputes.');
        }

        if (Merchant::query()->where('user_id', $user->id)->where('status', MerchantStatus::APPROVED)->exists()) {
            $blockers['merchant_profile'] = __('Ask support to close your merchant profile first.');
        }

        if (Agent::query()->where('user_id', $user->id)->where('status', AgentStatus::APPROVED)->exists()) {
            $blockers['agent_profile'] = __('Ask support to close your agent profile first.');
        }

        $liveCards = VirtualCard::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [VirtualCardStatus::Active->value, VirtualCardStatus::Pending->value, VirtualCardStatus::Inactive->value])
            ->exists();

        if ($liveCards) {
            $blockers['virtual_card'] = __('Empty and cancel your virtual cards first.');
        }

        $runningStakes = WalletEarnStake::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [WalletEarnStatus::Pending, WalletEarnStatus::Active])
            ->exists();

        if ($runningStakes) {
            $blockers['wallet_earn_stake'] = __('Wait for your Wallet Earn stakes to finish.');
        }

        return $blockers;
    }

    /**
     * @throws ValidationException  the password or the second-factor code is wrong
     * @throws NotifyErrorException the account still holds something, or is the shared demo
     */
    public function close(User $user, string $password, ?string $twoFactorCode): void
    {
        if (isDemoProtectedAccount($user->email)) {
            throw new NotifyErrorException(__('The shared demo account cannot be closed.'));
        }

        if (! Hash::check($password, $user->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => __('The current password is incorrect.')]);
        }

        if ($user->two_factor_enabled && ! $this->twoFactorCodeIsValid($user, $twoFactorCode)) {
            throw ValidationException::withMessages(['two_factor_code' => __('The two-factor code is incorrect.')]);
        }

        $avatar = $user->avatar;

        // Asked once, before the transaction: a first read inside it would fix
        // the snapshot the balance checks see before the locks below are held.
        $this->hasVaults();

        DB::transaction(function () use ($user): void {
            // Serialises against anything moving money in these wallets or
            // vaults right now, so the checks below read settled balances.
            // The user row first, as SubscriptionService takes it; vaults
            // before wallets, as VaultService does. A vault or wallet write
            // also takes a shared lock on the user row through the ledger's
            // foreign key, so two can still meet the other way round: the
            // database then abandons one, and this one is tried again.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($this->hasVaults()) {
                Vault::query()->where('user_id', $user->id)->lockForUpdate()->get();
            }
            Wallet::query()->where('user_id', $user->id)->lockForUpdate()->get();

            if (($blockers = $this->blockers($user)) !== []) {
                throw new NotifyErrorException(__('Your account cannot be closed yet. :reasons', ['reasons' => implode(' ', $blockers)]));
            }

            Wallet::query()->where('user_id', $user->id)->update(['status' => false]);

            // Empty by now, but a rule could still fire: auto-save would try a
            // switched-off wallet on every run and tell a closed account so.
            if ($this->hasVaults()) {
                Vault::query()->where('user_id', $user->id)->where('status', '!=', 'closed')
                    ->update(['status' => 'closed', 'closed_at' => now(), 'next_auto_at' => null]);
            }

            $user->forceFill([
                'first_name'                 => 'Deleted',
                'last_name'                  => 'User',
                'business_name'              => null,
                'business_address'           => null,
                'username'                   => 'deleted-'.$user->id,
                'email'                      => 'deleted-'.$user->id.'@deleted.invalid',
                'phone'                      => null,
                'phone_verified_at'          => null,
                'phone_verification_enabled' => false,
                'gender'                     => null,
                'birthday'                   => null,
                'country'                    => null,
                'state'                      => null,
                'city'                       => null,
                'postal_code'                => null,
                'address'                    => null,
                'avatar'                     => null,
                'google2fa_secret'           => null,
                'two_factor_enabled'         => false,
                'wallet_pin'                 => null,
                'password'                   => Hash::make(Str::random(64)),
                'remember_token'             => null,
                'status'                     => UserStatus::INACTIVE,
                'closed_at'                  => now(),
            ])->save();

            $user->tokens()->delete();
            UserPushToken::query()->where('user_id', $user->id)->delete();
            $user->pushSubscriptions()->delete();

            $sessions = (string) config('session.table', 'sessions');

            if (config('session.driver') === 'database' && Schema::hasTable($sessions)) {
                DB::table($sessions)->where('user_id', $user->id)->delete();
            }
        }, 3);

        // The photo is the one personal file on the row: best effort, after the commit.
        if (filled($avatar)) {
            try {
                Storage::disk('public')->delete(ltrim((string) $avatar, '/'));
            } catch (Throwable $e) {
                report($e);
            }
        }

        Log::info('Customer closed their account', ['user_id' => $user->id]);
    }

    private function hasVaults(): bool
    {
        return $this->hasVaults ??= Schema::hasTable('vaults');
    }

    private function twoFactorCodeIsValid(User $user, ?string $code): bool
    {
        return filled($code)
            && filled($user->google2fa_secret)
            && $this->twoFactor->verifyCode((string) $user->google2fa_secret, (string) $code);
    }
}
