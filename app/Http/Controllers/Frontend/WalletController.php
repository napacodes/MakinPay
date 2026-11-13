<?php

namespace App\Http\Controllers\Frontend;

use App\Constants\CurrencyRole;
use App\Enums\Tax\TaxableType;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\CreateWalletRequest;
use App\Http\Requests\Frontend\UpdateWalletStatusRequest;
use App\Models\Currency;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Tax\TaxEngine;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Log;
use Wallet;

class WalletController extends Controller
{
    public function index()
    {
        $wallets = auth()->user()->wallets()
            ->active()
            ->with(['currency.activeRoles'])
            ->get();
        $hasUnavailableWallets = auth()->user()->wallets()->count() > $wallets->count();

        return view('frontend.user.wallet.index', compact('wallets', 'hasUnavailableWallets'));
    }

    public function myQrCode(): View
    {
        $wallets = auth()->user()->wallets()
            ->with(['currency.activeRoles'])
            ->where('status', true)
            ->whereHas('currency', function (Builder $query): void {
                $query->where('status', true)
                    ->whereHas('roles', function (Builder $query): void {
                        $query->where('role_name', CurrencyRole::SENDER)
                            ->where('is_active', true);
                    });
            })
            ->latest()
            ->get();

        return view('frontend.user.wallet.my_qr_code', compact('wallets'));
    }

    public function currencyInfo($currency_id)
    {
        $currency = Currency::query()
            ->active()
            ->with(['roles'])
            ->findOrFail($currency_id);

        return view('frontend.user.wallet.partials._currency_info', compact('currency'))->render();
    }

    /**
     * @throws NotifyErrorException
     */
    public function create(CreateWalletRequest $request): RedirectResponse
    {
        $user       = $request->user();
        $currencyId = $request->integer('currency_id');

        // Fetch user wallets count and rank limits
        $rank        = $user->rank;
        $walletCount = $user->wallets()->count();
        $walletLimit = data_get($rank?->features, 'wallet_create');

        // Check if the wallet creation limit is reached
        if (is_numeric($walletLimit) && $walletCount >= (int) $walletLimit) {
            throw new NotifyErrorException(
                __('You have reached your wallet limit for :rankName Member', ['rankName' => $rank?->name ?? __('Unranked')])
            );
        }

        // Create the wallet for the authenticated user
        $user->createWallet($currencyId);

        // Notify success and redirect back
        notifyEvs('success', __('Wallet created successfully'));

        return redirect()->back();
    }

    public function status(UpdateWalletStatusRequest $request, WalletService $walletService)
    {
        $wallet = $request->user()
            ->wallets()
            ->with('currency')
            ->findOrFail($request->integer('id'));

        if ($request->boolean('status')) {
            try {
                $walletService->assertCurrencyOperational($wallet->currency);
            } catch (NotifyErrorException $exception) {
                return response()->json([
                    'status'  => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }
        }

        $wallet->update(['status' => $request->boolean('status')]);

        return response()->json(['status' => true, 'message' => __('Wallet status updated successfully')]);
    }

    public function supportedPaymentMethods($wallet_id)
    {
        $wallet = auth()->user()->wallets()
            ->active()
            ->find($wallet_id);

        if (! $wallet) {
            return response()->json([
                'message' => __('The selected wallet is no longer available.'),
            ], 422);
        }

        // Fetch supported payment methods based on the wallet's currency
        $taxes = app(TaxEngine::class);

        $paymentMethods = $wallet->supportedPaymentMethods($wallet->name)
            ->map(function ($method) use ($wallet, $taxes) {
                // Read the role-aware charge components off the model first —
                // toArray() would only expose the raw base columns, and the
                // browser preview must use the same numbers the server will.
                $components = [
                    'charge_fixed'   => $method->charge_fixed,
                    'charge_percent' => $method->charge_percent,
                    'charge_min'     => $method->charge_min,
                    'charge_max'     => $method->charge_max,
                ];

                $paymentMethod = $method
                    ->makeHidden(['fields', 'receive_payment_details', 'status', 'created_at', 'updated_at'])
                    ->toArray();

                // The method's own rate, so the preview matches what the server
                // will actually convert at. This used to be overwritten with
                // the wallet currency's rate, which ignored the per-method
                // "Conversion Rate" the admin configures.
                $paymentMethod['conversion_rate'] = $method->conversion_rate;
                $paymentMethod['decimals']        = currencyDecimals($wallet->currency->code);
                $paymentMethod['customer_phone']  = $method->customerPhoneMode()->value;

                // The range the customer can actually use. Two parties limit
                // this payment — the platform in base currency and the
                // processor in its own — so the usable range is where they
                // overlap. Showing the platform's own ceiling while the
                // processor caps it lower would invite an amount that gets
                // rejected after the customer has committed to the flow.
                $paymentMethod['min_deposit']         = $method->effectiveMinAmount();
                $paymentMethod['max_deposit']         = $method->effectiveMaxAmount();
                $paymentMethod['limited_by_provider'] = $method->isLimitedByProvider();

                // GF6: the deposit tax rules, the fixed part already in this
                // method's currency, for the preview to mirror.
                $paymentMethod['tax_rules'] = $taxes->previewRules(TaxableType::Deposit, auth()->user(), DecimalMoney::rate($method->conversion_rate ?? 1));

                return array_merge($paymentMethod, $components);
            })
            ->toArray();

        // Return as JSON response
        return response()->json($paymentMethods);
    }

    public function validateRecipient($role, $emailOrWalletId)
    {
        $status         = 'error';
        $message        = __('Invalid input provided');
        $type           = null;
        $currencyRole   = null;
        $walletListView = null;
        $wallet         = null;

        $featureKey     = $this->featureKeyForRole((string) $role);
        $featureManager = app(FeatureManager::class);
        $accessRule     = $featureKey !== null
            ? $featureManager->find($featureKey)?->accessRules->firstWhere('panel', $featureManager->currentPanel())
            : null;
        $allowedMethods = $accessRule?->allowedIdentifierMethods()
            ?? [WalletService::IDENTIFIER_EMAIL, WalletService::IDENTIFIER_MOBILE, WalletService::IDENTIFIER_WALLET_ID];

        $detectedType = Wallet::detectIdentifierType((string) $emailOrWalletId);

        if (! in_array($detectedType, $allowedMethods, true)) {
            return response()->json([
                'type'              => 'invalid',
                'available_wallets' => null,
                'currency_role'     => null,
                'wallet_currency'   => null,
                'currency_rate'     => null,
                'message'           => __('This identifier method is not allowed. Please use a permitted lookup method.'),
                'status'            => 'error',
            ]);
        }

        if ($detectedType === WalletService::IDENTIFIER_EMAIL) {
            $type = 'email';
            $user = User::where('email', $emailOrWalletId)->first();

            if (! $user) {
                $message = __('User not found');
            } elseif ($user->id === auth()->id()) {
                $message = __('You cannot perform this action for yourself');
            } elseif ($accessRule?->requiresVerifiedEmail() && $user->email_verified_at === null) {
                $message = __('Recipient email is not verified.');
            } else {
                $availableWallets = auth()->user()->activeWallets($role);
                $status           = 'success';
                $message          = __('User: :name', ['name' => "{$user->first_name} {$user->last_name}"]);
                $walletListView   = $this->renderWalletListView($availableWallets);
            }
        } elseif ($detectedType === WalletService::IDENTIFIER_MOBILE) {
            $type = 'mobile';
            $user = Wallet::lookupUserByPhone((string) $emailOrWalletId);

            if ($user === false) {
                $user    = null;
                $message = __('This number matches more than one account. Use the recipient\'s email or wallet ID instead.');
            } elseif (! $user) {
                $message = __('User not found');
            } elseif ($user->id === auth()->id()) {
                $message = __('You cannot perform this action for yourself');
            } elseif ($accessRule?->requiresVerifiedMobile() && ! $user->hasVerifiedPhone()) {
                $message = __('Recipient mobile number is not verified.');
            } else {
                $availableWallets = auth()->user()->activeWallets($role);
                $status           = 'success';
                $message          = __('User: :name', ['name' => "{$user->first_name} {$user->last_name}"]);
                $walletListView   = $this->renderWalletListView($availableWallets);
            }
        } else {
            $type = 'wallet_uuid';

            try {
                $wallet = Wallet::getWalletByUniqueId((string) $emailOrWalletId);
            } catch (NotifyErrorException) {
                $wallet = null;
            }

            $availableMyWallet = $wallet
                ? auth()->user()->activeWallets()->where('currency_id', $wallet->currency_id)
                : collect();

            if (! $wallet) {
                $message = __('Wallet not found');
            } elseif (! $wallet->status || ! $wallet->currency?->status) {
                $message = __('Recipient wallet is currently unavailable');
            } elseif (! $wallet->hasCurrencyRole($role)) {
                $message = __('This wallet does not permit :role', ['role' => title($role)]);
            } elseif ($availableMyWallet->isEmpty()) {
                $message = __('You do not have a wallet in this currency');
            } else {
                $user = User::find($wallet->user_id);

                if (! $user) {
                    $message = __('User associated with this wallet not found');
                } elseif ($user->id === auth()->id()) {
                    $message = __('You cannot perform this action for yourself');
                } else {
                    $status       = 'success';
                    $message      = __('User: :name', ['name' => "{$user->first_name} {$user->last_name}"]);
                    $currencyRole = $wallet->getCurrencyRoleInfo($role);

                    // Wrap the single wallet in an array for consistent handling in the view
                    $walletListView = $this->renderWalletListView($availableMyWallet);
                }
            }
        }

        return response()->json([
            'type'              => $type ?? 'invalid',
            'available_wallets' => $walletListView,
            'currency_role'     => $status === 'success' ? $currencyRole : null,
            'wallet_currency'   => $status === 'success' ? $wallet?->name : null,
            'currency_rate'     => $status === 'success' ? DecimalMoney::rate($wallet?->currency->exchange_rate ?? 1) : null,
            // GF6: a wallet ID fixes the currency before a wallet is picked, so
            // the preview needs the tax rules here too, or it shows a total
            // without the tax until the customer picks from the list.
            'tax_rules' => $status === 'success' && $wallet !== null && (string) $role === CurrencyRole::SENDER
                ? app(TaxEngine::class)->previewRules(TaxableType::SendMoney, auth()->user(), Wallet::fxRate($wallet))
                : [],
            'message' => $message,
            'status'  => $status,
        ]);
    }

    private function featureKeyForRole(string $role): ?string
    {
        return match ($role) {
            CurrencyRole::SENDER        => 'send_money',
            CurrencyRole::REQUEST_MONEY => 'request_money',
            default                     => null,
        };
    }

    /**
     * Returns wallet information for a given wallet ID.
     */
    public function getWalletInfo($role, $walletId)
    {
        try {
            $wallet = auth()->user()->wallets()
                ->active((string) $role)
                ->with(['currency.activeRoles'])
                ->find($walletId);

            if (! $wallet) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('The selected wallet is no longer available.'),
                ], 422);
            }

            // Safely retrieve wallet role and related currency details
            $walletRole = $wallet->getCurrencyRoleInfo($role) ?? [];
            $currency   = $wallet->currency;
            $walletInfo = array_merge($walletRole, [
                'wallet_currency' => $wallet->name,
                'currency_rate'   => DecimalMoney::rate($currency->exchange_rate ?? 1),
                // GF6: the tax rules this customer's transfer would carry,
                // the fixed part already in the wallet's currency, for the
                // preview to mirror. The server prices the real charge.
                'tax_rules' => (string) $role === CurrencyRole::SENDER
                    ? app(TaxEngine::class)->previewRules(TaxableType::SendMoney, auth()->user(), Wallet::fxRate($wallet))
                    : [],
            ]);

            return response()->json([
                'status' => 'success',
                'data'   => $walletInfo,
            ]);
        } catch (Exception $e) {
            // Log error for debugging
            Log::error($e);

            return response()->json([
                'status'  => 'error',
                'message' => __('An unexpected error occurred'),
            ], 500);
        }
    }

    /**
     * Render the wallet list view.
     */
    protected function renderWalletListView($wallets)
    {
        return view('frontend.user.wallet.partials._available_wallets', compact('wallets'))->render();
    }
}
