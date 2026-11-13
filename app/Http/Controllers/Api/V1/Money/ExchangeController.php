<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Money;

use App\Constants\CurrencyRole;
use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\Wallet as WalletModel;
use App\Services\Money\ExchangeMoneyService;
use App\Services\Money\WalletPinGuard;
use App\Services\Tax\TaxPresenter;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exchanging between two of the customer's own wallets.
 *
 * The preview and the exchange price through the same
 * ExchangeMoneyService::price(), so a customer is never shown one number and
 * charged another. The preview writes nothing and holds nothing: there is no
 * quote to expire, because the confirm re-derives every figure from the
 * wallets and the currency role at the moment it runs.
 *
 * A rate the client sends back is used for ONE purpose - to notice that the
 * market moved between the two taps and refuse - and never to price the
 * trade. A client that could price its own trade could price it at anything.
 */
class ExchangeController extends Controller
{
    /**
     * How far the effective rate may move between the preview and the confirm
     * before the trade is refused and the customer is asked again. A tenth of
     * a percent: enough that a live feed ticking does not become a wall of
     * refusals, small enough that nobody is given a materially different trade
     * from the one they agreed to.
     */
    private const DRIFT_TOLERANCE = '0.001';

    /**
     * How long the app shows a quote before pricing again. The preview is
     * stateless, so this is a promise about the app's own behaviour rather
     * than a lock the server holds: the confirm still re-prices, and the
     * drift guard above still decides.
     */
    public const QUOTE_SECONDS = 30;

    public function __construct(private readonly ExchangeMoneyService $exchange, private readonly WalletPinGuard $pins) {}

    /**
     * What this exchange would cost, right now.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        [$source, $destination] = $this->wallets($request, $validated);

        if ($source === null || $destination === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $priced = $this->exchange->price($source, $destination, (string) $validated['amount']);

        if ($dust = $this->refuseDust($priced, $source, $destination)) {
            return $dust;
        }

        return ApiResponse::ok([
            'quote' => $this->quoteBody($priced, $source, $destination),
        ]);
    }

    /**
     * Do it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, [
            // Optional, and only ever used to detect drift.
            'expected_rate' => ['sometimes', 'numeric'],
        ]);

        // The same confirmation Send and Withdraw ask for: a customer who set
        // a wallet PIN is asked for it on every move of money, including this.
        if ($request->user()->hasWalletPin()) {
            $this->pins->assert($request->user(), $request->validate(['wallet_pin' => ['required', 'digits:6']])['wallet_pin']);
        }

        [$source, $destination] = $this->wallets($request, $validated);

        if ($source === null || $destination === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $priced = $this->exchange->price($source, $destination, (string) $validated['amount']);

        if ($dust = $this->refuseDust($priced, $source, $destination)) {
            return $dust;
        }

        $quote = $this->quoteBody($priced, $source, $destination);

        if ($drift = $this->refuseOnDrift($request, $quote['rate'])) {
            return $drift;
        }

        /*
         * The service re-reads both wallets under a lock and prices them
         * again. The quote above is therefore a report of what was quoted,
         * not an instruction - deliberately, because anything carried from
         * here into the ledger would be a figure the request could influence.
         */
        $legs = $this->exchange->exchange($request->user(), $validated);

        return ApiResponse::created([
            'source_reference'      => $legs['source']->trx_id,
            'destination_reference' => $legs['destination']->trx_id,
            'quote'                 => $quote,
        ]);
    }

    /**
     * Refuse a trade too small to be expressed in one of the two currencies.
     *
     * A 0.001 typed into a wallet kept to two places rounds to nothing. The
     * customer would be shown 0.00 paid and 0.00 received while the ledger
     * moved the dust anyway, and the effective rate - received over paid -
     * would be a division by zero, which is how this was found.
     *
     * Both sides are checked. A figure large enough to state in the paying
     * currency can still round away in the receiving one, and that is the
     * worse of the two: money leaves and nothing arrives.
     *
     * @param array<string, mixed> $priced
     */
    private function refuseDust(array $priced, WalletModel $source, WalletModel $destination): ?JsonResponse
    {
        $paid     = ApiMoney::amount($priced['source_net'], $source->currency?->code);
        $received = ApiMoney::amount($priced['destination_net'], $destination->currency?->code);

        if (DecimalMoney::compare($paid, '0') > 0 && DecimalMoney::compare($received, '0') > 0) {
            return null;
        }

        return ApiResponse::error(
            ApiErrorCode::ValidationFailed,
            __('That amount is too small to exchange between these wallets.'),
            ['amount' => [__('That amount is too small to exchange between these wallets.')]],
        );
    }

    /**
     * Refuse when the rate has moved since the preview.
     *
     * The figure compared is the effective rate - what the customer actually
     * saw, destination received per source unit - and not either wallet's own
     * rate against the base currency. Both sides convert in an exchange, so
     * guarding one of them leaves the other free to move by any amount while
     * the guard reports everything is fine.
     */
    private function refuseOnDrift(Request $request, string $actual): ?JsonResponse
    {
        if (! $request->filled('expected_rate')) {
            return null;
        }

        $expected = DecimalMoney::rate((string) $request->input('expected_rate'));

        $withinTolerance = DecimalMoney::compare($expected, '0') > 0
            && DecimalMoney::compare($actual, '0')               > 0
            && DecimalMoney::compare(
                $this->relativeDifference($actual, $expected),
                self::DRIFT_TOLERANCE,
            ) <= 0;

        if ($withinTolerance) {
            return null;
        }

        return ApiResponse::error(
            ApiErrorCode::ValidationFailed,
            __('The exchange rate has changed. Please review the new rate and try again.'),
            ['expected_rate' => [__('The exchange rate has changed.')]],
            extra: ['rate' => $actual],
        );
    }

    /**
     * |actual - expected| / actual, in exact decimal arithmetic. A rate can
     * carry eight places, which is precisely the scale at which doing this in
     * floats starts answering the wrong question.
     */
    private function relativeDifference(string $actual, string $expected): string
    {
        $difference = DecimalMoney::subtract($actual, $expected, DecimalMoney::CALCULATION_SCALE);

        return DecimalMoney::divide(ltrim($difference, '-'), $actual);
    }

    /**
     * @param  array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function quoteBody(array $quote, WalletModel $source, WalletModel $destination): array
    {
        $sourceCode      = $source->currency?->code;
        $destinationCode = $destination->currency?->code;

        $sourceNet      = ApiMoney::amount($quote['source_net'], $sourceCode);
        $destinationNet = ApiMoney::amount($quote['destination_net'], $destinationCode);

        return [
            // What the customer is shown, and the only figure the drift guard
            // reads back: destination received per whole source unit.
            'rate' => DecimalMoney::rate(DecimalMoney::divide($destinationNet, $sourceNet)),
            // The rate before the fee - the two base rates crossed - so the
            // app can say what the spread cost. Never used to price anything.
            'mid_rate'   => bccomp((string) $quote['source_rate'], '0', DecimalMoney::RATE_SCALE) > 0 ? DecimalMoney::rate(DecimalMoney::divide((string) $quote['destination_rate'], (string) $quote['source_rate'])) : null,
            'expires_in' => self::QUOTE_SECONDS,
            // Which way round `source.amount` and `source.total` are: under
            // `deducted` the total is the typed figure and the amount is what
            // converts after the fee; under `on_top` the amount is typed and
            // the total is amount plus fee. GF6: `source.tax` is on top of
            // the fee in both modes, a line per rule, included in the total.
            'fee_mode' => $quote['fee_mode']->value,
            'source'   => [
                'wallet'    => $source->uuid,
                'currency'  => $sourceCode,
                'decimals'  => ApiMoney::decimals($sourceCode),
                'amount'    => $sourceNet,
                'fee'       => ApiMoney::amount($quote['source_fee_in_wallet'], $sourceCode),
                'tax'       => TaxPresenter::api($quote['tax']),
                'total'     => ApiMoney::amount($quote['source_payable'], $sourceCode),
                'base_rate' => DecimalMoney::rate($quote['source_rate']),
            ],
            'destination' => [
                'wallet'    => $destination->uuid,
                'currency'  => $destinationCode,
                'decimals'  => ApiMoney::decimals($destinationCode),
                'amount'    => $destinationNet,
                'base_rate' => DecimalMoney::rate($quote['destination_rate']),
            ],
        ];
    }

    /**
     * Both wallets, scoped to the caller and to the exchange role exactly as
     * the service scopes them - so a preview cannot describe a trade the
     * exchange would refuse, and a wallet belonging to somebody else is not
     * found rather than refused.
     *
     * @param  array<string, mixed>                            $validated
     * @return array{0: WalletModel|null, 1: WalletModel|null}
     */
    private function wallets(Request $request, array $validated): array
    {
        $wallets = WalletModel::query()
            ->where('user_id', $request->user()->id)
            ->active(CurrencyRole::EXCHANGE)
            ->whereIn('uuid', [$validated['source_wallet_id'], $validated['destination_wallet_id']])
            ->with(['currency.roles'])
            ->get()
            ->keyBy('uuid');

        return [
            $wallets->get($validated['source_wallet_id']),
            $wallets->get($validated['destination_wallet_id']),
        ];
    }

    /**
     * @param  array<string, array<int, string>> $extra
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, array $extra = []): array
    {
        return $request->validate([
            'source_wallet_id'      => ['required', 'string'],
            'destination_wallet_id' => ['required', 'string', 'different:source_wallet_id'],
            'amount'                => ['required', 'numeric', 'gt:0'],
        ] + $extra);
    }
}
