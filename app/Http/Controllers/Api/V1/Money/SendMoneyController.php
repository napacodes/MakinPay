<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Money;

use App\Constants\CurrencyRole;
use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\Wallet as WalletModel;
use App\Services\Money\SendMoneyService;
use App\Services\Money\WalletPinGuard;
use App\Services\Tax\TaxPresenter;
use App\Services\WalletService;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sending money to another customer, from a phone.
 *
 * The preview and the transfer resolve through the same
 * SendMoneyService::quote(), so the recipient named on the confirmation
 * screen and the fee shown beside them are the ones the transfer will use.
 * Nothing is held between the two calls: the confirm resolves everything
 * again, which is why there is no quote to expire.
 *
 * THE PREVIEW RETURNS THE RECIPIENT'S NAME, and that is a deliberate
 * trade. It is what stops a customer sending money to a mistyped number, and
 * every wallet app does it - but it also means an authenticated caller can
 * turn an email or a phone number into a name. The mitigation is the money
 * rate limit rather than the read one: this endpoint is not something a
 * client should be able to run down a list with. Nothing else about the
 * recipient is sent - no email, no phone, no id.
 *
 * THE WALLET PIN IS REQUIRED HERE and is not required by the web form, which
 * never asks for one even from a customer who has set one (X13). The app is
 * deliberately the stricter of the two: a token in the wrong hands should not
 * be a way to empty a wallet that its owner has put a PIN on.
 */
class SendMoneyController extends Controller
{
    public function __construct(
        private readonly SendMoneyService $transfers,
        private readonly WalletPinGuard $pins,
        private readonly WalletService $wallets,
    ) {}

    /**
     * Who this is going to, and what it will cost.
     */
    public function preview(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$wallet, $validated] = $resolved;

        return ApiResponse::ok([
            'quote' => $this->quoteBody(
                $this->transfers->quote($request->user(), $validated),
                $wallet,
            ),
        ]);
    }

    /**
     * Send it.
     */
    public function store(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request, [
            'note'       => ['nullable', 'string', 'max:255'],
            'wallet_pin' => ['nullable', 'string'],
        ]);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$wallet, $validated] = $resolved;

        // Before anything is looked up, so a wrong PIN cannot be used to probe
        // which recipients exist.
        $this->pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        /*
         * Quoted, then sent - and send() resolves the whole thing again rather
         * than being handed this. The second lookup is the point: what goes
         * into the ledger must come from the service, not from a figure this
         * controller computed and could have been persuaded to change. The
         * quote below is a receipt of what was shown, and costs one more
         * indexed read.
         */
        $quote = $this->quoteBody($this->transfers->quote($request->user(), $validated), $wallet);

        $legs = $this->transfers->send($request->user(), $validated);

        return ApiResponse::created([
            'reference'           => $legs['sender']->trx_id,
            'recipient_reference' => $legs['recipient']->trx_id,
            'quote'               => $quote,
        ]);
    }

    /**
     * The sending wallet and the validated payload, or the refusal.
     *
     * The wallet is resolved before validation because the amount's rules
     * depend on the currency it is kept in, and guarded on is_string so an
     * array sent where a uuid belongs fails validation rather than reaching a
     * cast. The service is handed the primary key, keeping its signature the
     * same one the web form uses; it re-scopes to the caller regardless.
     *
     * @param  array<string, array<int, string>>                           $extra
     * @return JsonResponse|array{0: WalletModel, 1: array<string, mixed>}
     */
    private function resolve(Request $request, array $extra = []): JsonResponse|array
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid) ? $this->wallet($request, $uuid) : null;

        $validated = $request->validate([
            'wallet_id' => ['required', 'string'],
            'recipient' => ['required', 'string', 'max:255'],
            'amount'    => AmountEntry::rules($wallet?->currency?->code ?? siteCurrency()),
        ] + $extra);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $validated['wallet_id'] = $wallet->id;

        return [$wallet, $validated];
    }

    /**
     * @param  array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function quoteBody(array $quote, WalletModel $wallet): array
    {
        $code   = $wallet->currency?->code;
        $limits = $this->wallets->amountLimitsByRole($wallet, CurrencyRole::SENDER);

        return [
            'recipient' => [
                'name'   => $quote['recipient_wallet']->user?->name,
                'wallet' => $quote['recipient_wallet']->uuid,
            ],

            // Everything the customer sees, in the currency they typed in.
            'currency' => $code,
            'decimals' => ApiMoney::decimals($code),
            'amount'   => ApiMoney::amount($quote['net'], $code),
            'fee'      => ApiMoney::amount(
                DecimalMoney::subtract($quote['payable_before_tax'], $quote['net'], DecimalMoney::CALCULATION_SCALE),
                $code,
            ),

            // GF6: on top of the fee, a line per rule; zero with no lines when
            // no rule applies. Included in total.
            'tax'     => TaxPresenter::api($quote['tax']),
            'total'   => ApiMoney::amount($quote['payable'], $code),
            'balance' => ApiMoney::amount($wallet->balance, $code),

            // Reported rather than enforced here: the confirm re-checks both
            // against the wallet as it stands at that moment. An app that
            // greys out its own button is a convenience, not the guard.
            'sufficient' => DecimalMoney::compare($wallet->balance, $quote['payable']) >= 0,
            'limits'     => [
                'min' => $this->inWalletCurrency($limits['min'], $wallet),
                'max' => $this->inWalletCurrency($limits['max'], $wallet),
            ],

            /*
             * The ledger's own denomination, sent rather than hidden. The
             * limits are configured in the base currency and the amount is
             * typed in the wallet's; at a rate of one those are the same
             * number, which is exactly why a mismatch between them goes
             * unnoticed until a customer in another currency reports it.
             */
            'base' => [
                'currency' => siteCurrency(),
                'amount'   => ApiMoney::amount($quote['amount'], siteCurrency()),
                'fee'      => ApiMoney::amount($quote['fee'], siteCurrency()),
                'tax'      => ApiMoney::amount($quote['tax']->baseTotal, siteCurrency()),
                'total'    => ApiMoney::amount($quote['total'], siteCurrency()),
                'min'      => $limits['min'],
                'max'      => $limits['max'],
            ],
        ];
    }

    /**
     * A base-currency bound as the amount the customer would have to type.
     */
    private function inWalletCurrency(?string $baseAmount, WalletModel $wallet): ?string
    {
        if ($baseAmount === null) {
            return null;
        }

        return ApiMoney::amount(
            $this->wallets->conversionAmount($wallet, (float) $baseAmount),
            $wallet->currency?->code,
        );
    }

    /**
     * One of the caller's own wallets, by uuid. Somebody else's is not found
     * rather than refused, because a refusal confirms it exists.
     */
    private function wallet(Request $request, string $uuid): ?WalletModel
    {
        return WalletModel::query()
            ->where('user_id', $request->user()->id)
            ->where('uuid', $uuid)
            ->with(['currency.roles'])
            ->first();
    }
}
