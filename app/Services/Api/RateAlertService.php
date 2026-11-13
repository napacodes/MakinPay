<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Constants\CurrencyRole;
use App\Models\RateAlert;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Services\Money\ExchangeMoneyService;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use Throwable;

/**
 * The rate an alert watches, and the exchange it runs when it fires.
 *
 * The rate is the same cross rate the exchange screen shows - TO per one
 * FROM, from the two currencies' base rates - so an alert set from a quote
 * fires at the figure the customer was looking at.
 */
class RateAlertService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly ExchangeMoneyService $exchange,
    ) {}

    /**
     * TO per one FROM at this moment, or null when either side cannot be
     * priced - an unusable rate must not fire an alert, let alone a trade.
     */
    public function currentRate(string $from, string $to): ?string
    {
        try {
            $fromRate = $this->wallets->fxRate(strtoupper($from));
            $toRate   = $this->wallets->fxRate(strtoupper($to));
        } catch (Throwable) {
            return null;
        }

        if (bccomp($fromRate, '0', 18) <= 0 || bccomp($toRate, '0', 18) <= 0) {
            return null;
        }

        return DecimalMoney::rate(DecimalMoney::divide($toRate, $fromRate));
    }

    /**
     * Run the exchange an auto-exchange alert carries, and hand back the
     * paying leg's reference. The PIN was verified when the alert was set;
     * everything else - balance, limits, the wallets still being the
     * customer's and open for exchange - is checked again by the service.
     */
    public function autoExchange(RateAlert $alert): ?string
    {
        $source      = $alert->sourceWallet;
        $destination = $alert->destinationWallet;
        $user        = $alert->user;

        if ($user === null || $source === null || $destination === null || $alert->amount === null) {
            return null;
        }

        if ((int) $source->user_id !== (int) $user->id || (int) $destination->user_id !== (int) $user->id) {
            return null;
        }

        $legs = $this->exchange->exchange($user, [
            'source_wallet_id'      => $source->uuid,
            'destination_wallet_id' => $destination->uuid,
            'amount'                => (string) $alert->amount,
        ]);

        return $legs['source']->trx_id;
    }

    /**
     * The caller's wallet in [$code] that may exchange, if they hold one.
     */
    public function exchangeWallet(User $user, string $code): ?WalletModel
    {
        return WalletModel::query()
            ->where('user_id', $user->id)
            ->active(CurrencyRole::EXCHANGE)
            ->whereHas('currency', fn ($query) => $query->where('code', strtoupper($code)))
            ->with('currency')
            ->first();
    }
}
