<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\Money\WalletPinGuard;
use App\Services\VoucherService;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's vouchers, from the app: the ones they made, making one, and
 * redeeming someone else's.
 *
 * The code is returned because the website shows it to the voucher's creator,
 * with a copy button - sharing it is the point of making one. Nobody else's
 * voucher is ever listed.
 *
 * The status is told three ways where the website tells two: its badge reads
 * "Redeemed" for any voucher that is not available, a switched-off one
 * included. Here a voucher nobody redeemed is never called redeemed.
 *
 * Making and redeeming go through VoucherService, the website's own. The
 * amount to make one is entered in the site currency, as on the website's
 * form, and becomes a voucher in the wallet's currency. The wallet PIN is
 * asked for when one is set - money leaves a wallet; the website does not ask.
 * A voucher credits once however often it is redeemed: the service claims it
 * before crediting, and the replay key answers a retried request with the
 * first answer.
 */
class VoucherController extends Controller
{
    public function __construct(private readonly VoucherService $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        $page = Voucher::query()
            ->with('currency')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->latest('id')
            ->paginate(20);

        $page->through(fn (Voucher $voucher): array => $this->voucher($voucher));

        return ApiResponse::paginated($page);
    }

    public function store(Request $request, WalletPinGuard $pins): JsonResponse
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid)
            ? $request->user()->wallets()->with(['currency.activeRoles'])->where('uuid', $uuid)->first()
            : null;

        $validated = $request->validate([
            'wallet_id'  => ['required', 'string'],
            'amount'     => AmountEntry::rules(siteCurrency()),
            'wallet_pin' => ['nullable', 'string'],
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $voucher = $this->vouchers->issue($request->user(), $wallet, $validated['amount']);

        return ApiResponse::created($this->voucher($voucher->load('currency')));
    }

    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $transaction = $this->vouchers->redeem($request->user(), trim($validated['code']));

        return ApiResponse::created($this->credit($transaction));
    }

    /**
     * @return array<string, mixed>
     */
    private function voucher(Voucher $voucher): array
    {
        return [
            'id'       => $voucher->id,
            'code'     => $voucher->code,
            'amount'   => ApiMoney::amount((string) $voucher->amount, $voucher->currency?->code),
            'currency' => $voucher->currency?->code,
            'status'   => match (true) {
                $voucher->redeemed_at !== null => 'redeemed',
                (bool) $voucher->is_active     => 'available',
                default                        => 'inactive',
            },
            'redeemed_at' => $voucher->redeemed_at?->toIso8601String(),
            'created_at'  => $voucher->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{reference: string, amount: string, currency: string}
     */
    private function credit(Transaction $transaction): array
    {
        $code = (string) $transaction->payable_currency;

        return [
            'reference' => (string) $transaction->trx_id,
            'amount'    => ApiMoney::amount((string) $transaction->net_amount, $code),
            'currency'  => $code,
        ];
    }
}
