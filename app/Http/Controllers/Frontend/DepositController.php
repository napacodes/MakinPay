<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\GenerateCryptoAddressRequest;
use App\Models\Admin;
use App\Models\DepositMethod;
use App\Models\Transaction;
use App\Notifications\TemplateNotification;
use App\Services\Bitnob\BitnobProviderSettings;
use App\Services\Money\DepositMoneyService;
use App\Services\QRCodeService;
use App\Support\AmountEntry;
use App\Support\CustomerPhone;
use App\Support\Money;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Payment;
use Wallet;

class DepositController extends Controller
{
    /**
     * Maps each supported coin + network to the Bitnob deposit method code
     * that resolves the on-chain address.
     *
     * @var array<string, array<string, string>>
     */
    private const CRYPTO_METHODS = [
        'USDT' => [
            'erc20'   => 'bitnob_usdt',
            'polygon' => 'bitnob-usdt-polygon',
            'base'    => 'bitnob-usdt-base',
            'solana'  => 'bitnob-usdt-solana',
            'trc20'   => 'bitnob-usdt-trc20',
            'bep20'   => 'bitnob-usdt-bep20',
        ],
        'USDC' => [
            'erc20'   => 'bitnob-usdc-erc20',
            'polygon' => 'bitnob-usdc-polygon',
            'base'    => 'bitnob-usdc-base',
            'solana'  => 'bitnob-usdc-solana',
            'trc20'   => 'bitnob-usdc-trc20',
            'bep20'   => 'bitnob-usdc-bep20',
        ],
    ];

    /**
     * @var array<string, array{label: string, chain: string}>
     */
    private const CRYPTO_NETWORK_META = [
        'erc20'   => ['label' => 'ERC20', 'chain' => 'Ethereum'],
        'polygon' => ['label' => 'Polygon', 'chain' => 'Polygon PoS'],
        'base'    => ['label' => 'Base', 'chain' => 'Base'],
        'solana'  => ['label' => 'Solana', 'chain' => 'Solana'],
        'trc20'   => ['label' => 'TRC20', 'chain' => 'TRON'],
        'bep20'   => ['label' => 'BEP20', 'chain' => 'BNB Smart Chain'],
    ];

    /**
     * @var array<string, array{name: string, symbol: string}>
     */
    private const CRYPTO_COIN_META = [
        'USDT' => ['name' => 'Tether', 'symbol' => '₮'],
        'USDC' => ['name' => 'USD Coin', 'symbol' => '$'],
    ];

    public function create()
    {

        $wallets        = auth()->user()->activeWallets();
        $depositMethods = DepositMethod::active()->get();

        return view('frontend.user.deposit.create', compact('wallets', 'depositMethods'));
    }

    /**
     * Native crypto deposit UI: pick a coin, an amount and a network; the
     * address is generated inline via generateCryptoAddress().
     */
    public function cryptoDeposit()
    {
        $coins      = $this->availableCryptoCoins();
        $showFaucet = $this->shouldOfferTestnetFaucet();

        return view('frontend.user.deposit.crypto_deposit', compact('coins', 'showFaucet'));
    }

    /**
     * Only testnet coins move while the app is a demo or Bitnob is in sandbox
     * mode, so the faucet shortcut is offered instead of real funds.
     */
    private function shouldOfferTestnetFaucet(): bool
    {
        if (config('app.demo')) {
            return true;
        }

        $settings = app(BitnobProviderSettings::class);

        return $settings->isGatewayEnabled() && $settings->isSandbox();
    }

    /**
     * Create a pending stablecoin deposit for the chosen coin/network and
     * return the on-chain address, QR code and status URL as JSON.
     */
    public function generateCryptoAddress(GenerateCryptoAddressRequest $request, QRCodeService $qrCodeService)
    {
        $validated = $request->validated();

        $methodCode = self::CRYPTO_METHODS[$validated['coin']][$validated['network']] ?? null;

        if (! $methodCode) {
            return response()->json(['message' => __('This network is not available for the selected coin.')], 422);
        }

        $method = DepositMethod::active()->where('method_code', $methodCode)->first();

        if (! $method) {
            return response()->json(['message' => __('This network is currently unavailable.')], 422);
        }

        $wallet = auth()->user()->wallets()
            ->active()
            ->whereHas('currency', fn ($query) => $query->where('code', $validated['coin']))
            ->first();

        if (! $wallet) {
            return response()->json(['message' => __('You do not have a :coin wallet yet.', ['coin' => $validated['coin']])], 422);
        }

        // BitnobPaymentGateway::depositChain() reads the selected method from
        // the request to resolve the target chain.
        $request->merge(['payment_method' => $method->id]);

        try {
            [$transaction] = Payment::depositWithPaymentMethod($method->id, $validated['amount'], $wallet->id);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // The gateway writes the address on a freshly-loaded row; reload to
        // pick up the trx_data it persisted.
        $transaction->refresh();

        $address = $transaction->trx_data['bitnob_deposit_wallet'] ?? null;

        if (! $address) {
            return response()->json(['message' => __('Could not generate a deposit address. Please try again.')], 422);
        }

        return response()->json([
            'trx_id'  => $transaction->trx_id,
            'address' => $address,
            'network' => $transaction->trx_data['bitnob_deposit_label'] ?? strtoupper($validated['network']),
            // "Amount to Send" is an on-chain figure the customer copies into
            // a wallet, so it is rendered at the coin's own precision. Two
            // decimals turned a 10.123456 USDT invoice into "10.12" and the
            // deposit then under-paid by an amount the watcher never matched.
            'amount'     => Money::format($transaction->payable_amount, $transaction->payable_currency, withSymbol: false),
            'currency'   => $transaction->payable_currency,
            'qr'         => $qrCodeService->generate((string) $address),
            'status_url' => route('user.deposit.crypto.status', ['trx_id' => $transaction->trx_id]),
        ]);
    }

    /**
     * The coins the user can deposit — each with a matching wallet and at
     * least one active Bitnob network, preserving the configured order.
     *
     * @return array<int, array{code: string, name: string, symbol: string, networks: array<int, array{code: string, label: string, chain: string}>}>
     */
    private function availableCryptoCoins(): array
    {
        $walletCurrencies = auth()->user()->wallets()
            ->active()
            ->with('currency:id,code')
            ->get()
            ->pluck('currency.code')
            ->filter()
            ->all();

        $methodCodes = [];
        foreach (self::CRYPTO_METHODS as $networks) {
            foreach ($networks as $methodCode) {
                $methodCodes[] = $methodCode;
            }
        }

        $activeCodes = DepositMethod::active()
            ->whereIn('method_code', $methodCodes)
            ->pluck('method_code')
            ->all();

        $coins = [];

        foreach (self::CRYPTO_METHODS as $coin => $networks) {
            if (! in_array($coin, $walletCurrencies, true)) {
                continue;
            }

            $availableNetworks = [];

            foreach ($networks as $network => $methodCode) {
                if (in_array($methodCode, $activeCodes, true)) {
                    $availableNetworks[] = [
                        'code'  => $network,
                        'label' => self::CRYPTO_NETWORK_META[$network]['label'],
                        'chain' => self::CRYPTO_NETWORK_META[$network]['chain'],
                    ];
                }
            }

            if ($availableNetworks === []) {
                continue;
            }

            $coins[] = [
                'code'     => $coin,
                'name'     => self::CRYPTO_COIN_META[$coin]['name'],
                'symbol'   => self::CRYPTO_COIN_META[$coin]['symbol'],
                'networks' => $availableNetworks,
            ];
        }

        return $coins;
    }

    public function store(Request $request, DepositMoneyService $deposits)
    {
        $validatedData = $request->validate([
            'wallet_id'      => 'required|exists:wallets,id',
            'payment_method' => 'required|exists:deposit_methods,id',
            // Typed in the wallet's own currency — a deposit method may only
            // serve a wallet of its own currency — so the floor is one unit of
            // that currency, not a flat 0.01.
            'amount' => AmountEntry::rules(
                AmountEntry::currencyForWalletId($request->input('wallet_id'), $request->user()?->getAuthIdentifier())
                    ?? siteCurrency()
            ),
            // The number a mobile-money gateway asks to approve the payment.
            'customer_phone' => CustomerPhone::rules(),
        ]);

        try {
            [$transaction, $redirectUrl] = $deposits->deposit($request->user(), $validatedData);

            // Redirect the user to the payment gateway
            if (filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                // Redirect the user to the payment gateway for URL
                return redirect()->away($redirectUrl);
            } else {
                // Return the HTML view directly if $redirectUrl is HTML content
                return response($redirectUrl, 200)->header('Content-Type', 'text/html');

            }

        } catch (Exception $e) {
            // Redirect back with an error message
            notifyEvs('error', __('Deposit failed: :error', ['error' => $e->getMessage()]));

            return redirect()->back();
        }
    }

    public function credentials($method_id)
    {
        $method = DepositMethod::findOrFail($method_id);

        return view('frontend.user.deposit.partials._credentials_fields', compact('method'))->render();
    }

    public function cryptoAddress(string $trxId, QRCodeService $qrCodeService)
    {
        $transaction = $this->findCryptoDepositTransaction($trxId);

        abort_if(empty($transaction->trx_data['bitnob_deposit_wallet']), 404);

        $qrCode     = $qrCodeService->generate((string) $transaction->trx_data['bitnob_deposit_wallet']);
        $showFaucet = $this->shouldOfferTestnetFaucet();

        return view('frontend.user.deposit.crypto_address', compact('transaction', 'qrCode', 'showFaucet'));
    }

    public function cryptoStatus(string $trxId)
    {
        $transaction = $this->findCryptoDepositTransaction($trxId);

        return response()->json([
            'status'   => $transaction->status->value,
            'redirect' => $transaction->status === TrxStatus::COMPLETED
                ? route('user.transaction.index', ['search' => $transaction->trx_id])
                : null,
        ]);
    }

    protected function findCryptoDepositTransaction(string $trxId): Transaction
    {
        return Transaction::query()
            ->where('trx_id', $trxId)
            ->where('user_id', auth()->id())
            ->where('trx_type', TrxType::DEPOSIT)
            ->firstOrFail();
    }

    public function history()
    {
        return view('frontend.user.deposit.history');
    }

    protected function depositNotification($transaction)
    {
        // Notify the user
        $transaction->user->notify(new TemplateNotification(
            identifier: 'deposit_user_submitted',
            data: [
                'amount' => $transaction->amount.' '.$transaction->currency,
                'method' => $transaction->provider,
                'trx'    => $transaction->trx_id,
            ],
            action: route('user.transaction.index')
        ));

        // Notify the admin
        $admins = Admin::permission('deposit-notification')->get();
        Notification::send($admins, new TemplateNotification(
            identifier: 'deposit_admin_notify_submission',
            data: [
                'user'   => $transaction->user->name,
                'amount' => $transaction->amount.' '.$transaction->currency,
                'method' => $transaction->provider,
                'trx'    => $transaction->trx_id,
            ],
            sender: $transaction->user,
            action: route('admin.deposit.manual-request')
        ));
    }
}
