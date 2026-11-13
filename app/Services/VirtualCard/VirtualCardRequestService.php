<?php

declare(strict_types=1);

namespace App\Services\VirtualCard;

use App\Enums\VirtualCard\CardholderStatus;
use App\Enums\VirtualCard\VirtualCardRequestStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\Admin;
use App\Models\Cardholders;
use App\Models\User;
use App\Models\VirtualCardProvider;
use App\Models\VirtualCardRequest;
use App\Models\Wallet;
use App\Notifications\TemplateNotification;
use App\Services\WalletService;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Asking for a virtual card - for the website and the app alike.
 *
 * Nothing is debited here: the initial load leaves the wallet when an
 * administrator approves the request, and that approval re-checks the balance
 * under a lock. What is checked now is what the customer could otherwise only
 * learn days later - that a provider issues this network in this wallet's
 * currency, and that the wallet holds the load.
 */
class VirtualCardRequestService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * @throws NotifyErrorException
     */
    public function submit(User $user, Wallet $wallet, int $cardholderId, string $network, float|int|string $initialLoad, ?string $theme = null): VirtualCardRequest
    {
        if ((int) $wallet->user_id !== (int) $user->id) {
            throw new NotifyErrorException(__('The selected wallet is unavailable.'));
        }

        $wallet->loadMissing('currency');
        $this->wallets->assertOperational($wallet);

        $cardholder = Cardholders::query()
            ->whereKey($cardholderId)
            ->where('user_id', $user->id)
            ->where('status', CardholderStatus::APPROVED->value)
            ->first();

        if (! $cardholder) {
            throw new NotifyErrorException(__('Choose one of your approved cardholders.'));
        }

        $provider = VirtualCardProvider::active()
            ->whereJsonContains('supported_networks', $network)
            ->whereJsonContains('supported_currencies', $wallet->currency->code)
            ->first();

        if (! $provider) {
            throw new NotifyErrorException(__('Selected network or currency is not supported by any provider.'));
        }

        $initialLoad = round((float) $initialLoad, 2);

        if ((float) $wallet->balance < $initialLoad) {
            throw new NotifyErrorException(__('Your :currency wallet balance is not enough to cover the initial load of :amount.', [
                'currency' => $wallet->currency->code,
                'amount'   => $initialLoad,
            ]));
        }

        $cardRequest = VirtualCardRequest::create([
            'cardholder_id'       => $cardholder->id,
            'wallet_id'           => $wallet->id,
            'user_id'             => $user->id,
            'network'             => $network,
            'theme'               => $theme ?? 'midnight',
            'status'              => VirtualCardRequestStatus::Pending,
            'initial_load_amount' => $initialLoad,
        ]);

        $this->tellAdministrators($user, $wallet, $network);

        return $cardRequest;
    }

    /**
     * Best effort, after the request is written. Telling the administrators
     * is a courtesy; it used to run inside the transaction, so an install
     * without the `virtual-card-notification` permission - Spatie throws on an
     * unknown permission name - could not file a card request at all.
     */
    private function tellAdministrators(User $user, Wallet $wallet, string $network): void
    {
        try {
            $admins = Admin::permission('virtual-card-notification')->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new TemplateNotification(
                    identifier: 'virtual_card_admin_notify_request',
                    data: [
                        'user'    => $user->full_name,
                        'network' => $network,
                        'wallet'  => $wallet->currency->code,
                    ],
                    sender: $user,
                    action: route('admin.virtual-card.requests.awaiting')
                ));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
