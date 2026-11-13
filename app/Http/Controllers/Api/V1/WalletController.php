<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Api\V1\Concerns\ManagesWalletExtras;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateWalletRequest;
use App\Http\Resources\Api\V1\WalletResource;
use App\Models\Currency;
use App\Models\Wallet;
use App\Services\Api\BalanceHistoryService;
use App\Services\WalletService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's own wallets.
 *
 * Every query begins at $request->user()->wallets(). No route takes a user id,
 * and a uuid belonging to somebody else is answered as not found rather than
 * forbidden - forbidden would confirm the wallet exists, and a uuid is the one
 * thing a stranger might plausibly have seen, since it is what a receive link
 * and a payment QR carry.
 */
class WalletController extends Controller
{
    use ManagesWalletExtras;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly BalanceHistoryService $history,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $wallets = $request->user()
            ->wallets()
            ->with('currency.roles')
            ->active()
            ->get();

        return ApiResponse::ok(WalletResource::collection($wallets));
    }

    public function show(Request $request, string $wallet): JsonResponse
    {
        $record = $this->find($request, $wallet);

        return $record === null
            ? ApiResponse::error(ApiErrorCode::ResourceNotFound)
            : ApiResponse::ok(new WalletResource($record));
    }

    /**
     * The currencies this customer could still open a wallet in, and whether
     * their rank lets them open another at all.
     *
     * The limit is stated here rather than discovered on the POST so the app
     * can show "3 of 5 wallets" before the customer picks anything.
     */
    public function availableCurrencies(Request $request): JsonResponse
    {
        $user  = $request->user();
        $limit = $this->wallets->walletLimitFor($user);
        $count = $user->wallets()->count();

        return ApiResponse::ok([
            'limit'      => $limit,
            'count'      => $count,
            'can_create' => $limit === null || $count < $limit,
            'currencies' => $user->availableCurrenciesForCreateWallet()
                ->sortBy('code')
                ->values()
                ->map(fn (Currency $currency): array => [
                    'code'            => $currency->code,
                    'name'            => $currency->name,
                    'symbol'          => $currency->symbol,
                    'symbol_position' => $currency->symbol_position,
                    'type'            => $currency->type,
                    'decimals'        => ApiMoney::decimals($currency->code),
                ]),
        ]);
    }

    /**
     * Open a wallet in another currency - the app's "+ Add currency".
     *
     * The same checks as the website's WalletController::create, in the same
     * order: the rank's wallet limit first, then one wallet per currency. A
     * second request for a currency the customer already holds answers with
     * that wallet rather than a refusal, so a retried tap cannot fail on its
     * own success.
     */
    public function store(CreateWalletRequest $request): JsonResponse
    {
        $user     = $request->user();
        $currency = Currency::query()->where('code', $request->validated('currency'))->firstOrFail();
        $existing = $user->wallets()->with('currency.roles')->where('currency_id', $currency->id)->first();

        if ($existing !== null) {
            return ApiResponse::ok(new WalletResource($existing));
        }

        try {
            $this->wallets->assertWithinWalletLimit($user);
            $wallet = $this->wallets->createWalletForCurrency($user, $currency->id);
        } catch (NotifyErrorException $e) {
            return ApiResponse::error(ApiErrorCode::Forbidden, $e->getMessage());
        }

        return ApiResponse::created(new WalletResource($wallet->load('currency.roles')));
    }

    /**
     * Daily closing balances across every active wallet, in the display
     * currency, for the Home sparkline. See BalanceHistoryService.
     */
    public function history(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', '30d');

        if (! BalanceHistoryService::isRange($range)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, null, ['range' => [__('Unknown range.')]]);
        }

        $user     = $request->user();
        $code     = strtoupper(trim((string) $request->query('currency', '')));
        $currency = $code === '' ? null : Currency::query()->where('code', $code)->where('status', true)->first();
        $display  = $currency instanceof Currency && $currency->isConvertible() ? strtoupper((string) $currency->code) : $user->displayCurrency();

        return ApiResponse::ok($this->history->forUser($user, $range, $display));
    }

    /**
     * One wallet's daily closing balances in its own currency.
     */
    public function walletHistory(Request $request, string $wallet): JsonResponse
    {
        $record = $this->find($request, $wallet);

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $range = (string) $request->query('range', '30d');

        if (! BalanceHistoryService::isRange($range)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, null, ['range' => [__('Unknown range.')]]);
        }

        return ApiResponse::ok($this->history->forWallet($record, $range));
    }

    /**
     * The code somebody else scans to pay this wallet.
     *
     * The SVG is returned inline rather than as a URL so the app can render it
     * offline and without a second authenticated round trip.
     */
    public function qr(Request $request, string $wallet): JsonResponse
    {
        $record = $this->find($request, $wallet);

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $size = (int) $request->integer('size', 260);
        $size = max(120, min($size, 600));

        return ApiResponse::ok([
            'uuid'        => $record->uuid,
            'receive_url' => $record->receiveMoneyUrl(),
            'qr_svg'      => $record->receiveMoneyQrCodeSvg($size),
        ]);
    }

    /**
     * Scoped to the caller, always. `whereUuid` on the relation, never a
     * global lookup followed by an ownership check - the two are only
     * equivalent while somebody remembers to write the second half.
     */
    private function find(Request $request, string $uuid): ?Wallet
    {
        return $request->user()
            ->wallets()
            ->with('currency.roles')
            ->where('uuid', $uuid)
            ->first();
    }
}
