<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Money;

use App\Enums\Api\ApiErrorCode;
use App\Enums\MerchantStatus;
use App\Exceptions\MerchantPaymentFailed;
use App\Http\Controllers\Controller;
use App\Models\Transaction as TransactionModel;
use App\Models\Wallet as WalletModel;
use App\Services\Api\CrossCurrencyCheckoutService;
use App\Services\Money\MerchantWalletPaymentService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wallet;

/**
 * Paying a merchant from a phone - the customer's side of a QR scan.
 *
 * The scan gives the app a checkout reference. It reads the checkout to show
 * who is being paid and how much, then pays it with the customer's wallet PIN.
 * Both go through MerchantWalletPaymentService, which is the same code the
 * hosted checkout page uses.
 *
 * A CHECKOUT REFERENCE IS NOT A SECRET, and this endpoint is deliberately
 * built as though it were printed on a wall - which, on a merchant's counter,
 * it is. Reading one tells you a business name and an amount, which is what a
 * customer standing at that counter is entitled to see and is all a passer-by
 * learns. Paying one takes the payer's own PIN and debits the payer's own
 * wallet, so a reference somebody else scanned buys them nothing.
 *
 * TWO KINDS OF REFUSAL reach the app differently, because they mean different
 * things to a customer. A wrong PIN leaves the checkout open and is a
 * VALIDATION_FAILED they can retry. A closed checkout - insufficient balance,
 * a merchant paying themselves - has already been failed and the merchant
 * already told, so it carries the merchant's own cancel URL for the app to
 * return the customer to.
 */
class MerchantPaymentController extends Controller
{
    public function __construct(private readonly MerchantWalletPaymentService $payments, private readonly CrossCurrencyCheckoutService $conversions) {}

    /**
     * What this checkout is asking for.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $checkout = $this->checkout($reference);

        if ($checkout === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $this->payments->assertPayable($checkout);

        $merchant = $this->payments->merchantFor($checkout);
        $currency = $this->payments->currencyFor($checkout);

        $environment = $this->payments->environmentFor($checkout);

        return ApiResponse::ok([
            'checkout' => [
                'reference' => $checkout->trx_id,
                'merchant'  => [
                    'name'     => $merchant->business_name,
                    'logo'     => $merchant->business_logo,
                    'verified' => $merchant->status === MerchantStatus::APPROVED,
                ],
                'amount'   => ApiMoney::amount($checkout->payable_amount, $currency),
                'currency' => $currency,
                'decimals' => ApiMoney::decimals($currency),

                // Sandbox checkouts move no real money, and an app that does
                // not say so is one a merchant will test with and then be
                // surprised by.
                'environment' => $environment->value,
                'is_sandbox'  => $environment->isSandbox(),

                // Whether this customer could pay it from a wallet they hold.
                'payable_from_wallet' => $payable = Wallet::getWalletByUserId($request->user()->id, $currency) !== null,

                // When they hold none: the wallet in another currency that
                // could cover it, and what that would cost them.
                'conversion' => $payable ? null : $this->conversions->suggest($request->user(), $checkout),
            ],
        ]);
    }

    /**
     * Pay it.
     */
    public function pay(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate([
            'wallet_pin' => ['required', 'digits:6'],
            // A wallet in another currency to pay from, exchanged on the way.
            'wallet_id' => ['sometimes', 'string'],
        ]);

        $checkout = $this->checkout($reference);

        if ($checkout === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $this->payments->assertPayable($checkout);

        $merchant = $this->payments->merchantFor($checkout);

        // Before anything is looked up, so a wrong PIN cannot be used to probe
        // which references exist.
        $this->payments->assertPin($request->user(), $validated['wallet_pin']);

        $wallet = Wallet::getWalletByUserId(
            $request->user()->id,
            $this->payments->currencyFor($checkout),
        );

        if (isset($validated['wallet_id'])) {
            $source = WalletModel::query()->where('user_id', $request->user()->id)->where('uuid', $validated['wallet_id'])->with('currency.roles')->first();

            if ($source === null) {
                return ApiResponse::error(ApiErrorCode::ResourceNotFound);
            }

            if ($source->currency?->code !== $this->payments->currencyFor($checkout)) {
                try {
                    $paid = $this->conversions->pay($request->user(), $source, $checkout, $merchant);
                } catch (MerchantPaymentFailed $failure) {
                    return ApiResponse::error(ApiErrorCode::ValidationFailed, $failure->getMessage(), extra: ['checkout_closed' => true, 'return_url' => $failure->redirectUrl]);
                }

                return ApiResponse::created([
                    'reference'          => $paid['settled']->trx_id,
                    'status'             => $paid['settled']->status?->value,
                    'exchange_reference' => $paid['exchange']->trx_id,
                    'return_url'         => $checkout->trx_data['success_redirect'] ?? null,
                ]);
            }
        }

        if (! $wallet) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                __('You do not have a wallet for this payment currency.'),
            );
        }

        try {
            $settled = $this->payments->pay($request->user(), $wallet, $checkout, $merchant);
        } catch (MerchantPaymentFailed $failure) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                $failure->getMessage(),
                extra: [
                    'checkout_closed' => true,
                    'return_url'      => $failure->redirectUrl,
                ],
            );
        }

        return ApiResponse::created([
            'reference'  => $settled->trx_id,
            'status'     => $settled->status?->value,
            'return_url' => $checkout->trx_data['success_redirect'] ?? null,
        ]);
    }

    /**
     * A checkout by its reference.
     *
     * Not scoped to a user, deliberately: the whole point of a checkout is
     * that somebody other than the merchant pays it, so the payer never owns
     * the row. What stops a stranger doing anything with it is that paying
     * needs their own PIN and debits their own wallet.
     */
    private function checkout(string $reference): ?TransactionModel
    {
        return TransactionModel::query()
            ->where('trx_id', $reference)
            ->first();
    }
}
