<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Money;

use App\Enums\AmountFlow;
use App\Enums\Api\ApiErrorCode;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TransactionResource;
use App\Models\Transaction as TransactionModel;
use App\Models\Wallet as WalletModel;
use App\Notifications\MoneyRequestReminderNotification;
use App\Services\Api\CounterpartyResolver;
use App\Services\Money\RequestMoneyService;
use App\Services\Money\WalletPinGuard;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asking another customer for money, from a phone.
 *
 * A request is two pending rows, one each way, and which of them a caller is
 * looking at decides what they may do with it. The listing says so in
 * `direction` - `incoming` when somebody is asking YOU, `outgoing` when you
 * asked them - so an app does not have to work it out from an amount flow it
 * would have to know the convention for.
 *
 * WHO MAY ANSWER is RequestMoneyService's to decide, not this controller's.
 * The same rule serves the web panel, which is the point: the answer used to
 * live in a private method on a controller and would have had to be written a
 * second time here, which is how two surfaces come to disagree about whose
 * consent a payment is.
 */
class RequestMoneyController extends Controller
{
    public function __construct(private readonly RequestMoneyService $requests, private readonly CounterpartyResolver $counterparties) {}

    /**
     * Ask.
     */
    public function store(Request $request): JsonResponse
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid) ? $this->wallet($request, $uuid) : null;

        $validated = $request->validate([
            'wallet_id' => ['required', 'string'],
            'recipient' => ['required', 'string', 'max:255'],
            'amount'    => AmountEntry::rules($wallet?->currency?->code ?? siteCurrency()),
            'note'      => ['nullable', 'string', 'max:255'],
            'due_at'    => ['nullable', 'date', 'after_or_equal:today'],
            'remind'    => ['sometimes', 'boolean'],
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $legs = $this->requests->request($request->user(), [
            'wallet_id' => $wallet->id,
            'recipient' => $validated['recipient'],
            'amount'    => $validated['amount'],
            'note'      => $validated['note'] ?? null,
        ]);

        $this->stamp($legs, $validated['due_at'] ?? null, (bool) ($validated['remind'] ?? false));

        return ApiResponse::created([
            'reference'       => $legs['requester']->trx_id,
            'payer_reference' => $legs['payer']->trx_id,
            'request'         => $this->body($legs['requester']),
        ]);
    }

    /**
     * The requests waiting on either side.
     *
     * Defaults to the pending ones, which is what a phone opens this screen
     * for; `status` widens it for a history view.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['sometimes', 'in:incoming,outgoing'],
            'status'    => ['sometimes', 'in:pending,completed,canceled,all'],
        ]);

        $query = TransactionModel::query()
            ->where('user_id', $request->user()->id)
            ->where('trx_type', TrxType::REQUEST_MONEY)
            ->latest('id');

        $status = $validated['status'] ?? 'pending';

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (($validated['direction'] ?? null) !== null) {
            $query->where(
                'amount_flow',
                $validated['direction'] === 'incoming' ? AmountFlow::MINUS : AmountFlow::PLUS,
            );
        }

        return ApiResponse::ok([
            'requests' => $query->get()->map(fn (TransactionModel $trx): array => $this->body($trx))->all(),
        ]);
    }

    /**
     * Pay it.
     *
     * Approving debits the payer, and an app token never expires, so the
     * wallet PIN is asked for when the payer has one - as sending money is.
     * It is checked before the request is looked up: the lookup is scoped to
     * the caller, so the answer to a wrong PIN says nothing about whether a
     * reference exists, and a wrong PIN moves nothing.
     */
    public function approve(Request $request, string $reference, WalletPinGuard $pins): JsonResponse
    {
        $validated = $request->validate([
            'wallet_pin' => ['nullable', 'string'],
        ]);

        $pins->assert($request->user(), $validated['wallet_pin'] ?? null, context: ['request_money' => $reference]);

        return ApiResponse::ok([
            'request' => $this->body(
                $this->requests->approve($request->user(), $reference, $this->remarks($request)),
            ),
        ]);
    }

    /**
     * Say no, or take it back.
     */
    public function decline(Request $request, string $reference): JsonResponse
    {
        return ApiResponse::ok([
            'request' => $this->body(
                $this->requests->decline($request->user(), $reference, $this->remarks($request)),
            ),
        ]);
    }

    /**
     * One request, from the point of view of whoever is holding this row.
     *
     * `direction` is the whole reason this is not just a TransactionResource:
     * the outgoing leg of a request is money coming IN to the person who
     * asked, and an app deciding which buttons to draw from `amount_flow`
     * would get it backwards exactly once and then move somebody's money.
     *
     * `can_approve` is convenience, not enforcement. The service checks again,
     * and an app that ignores this flag gets the same refusal as one that
     * never read it.
     *
     * @return array<string, mixed>
     */
    private function body(TransactionModel $transaction): array
    {
        $isPayersLeg = $transaction->amount_flow === AmountFlow::MINUS;

        $group = data_get($transaction->trx_data, 'group_id');

        return [
            'direction'    => $isPayersLeg ? 'incoming' : 'outgoing',
            'can_approve'  => $isPayersLeg && $transaction->status === TrxStatus::PENDING,
            'can_decline'  => $transaction->status                 === TrxStatus::PENDING,
            'note'         => $transaction->remarks,
            'due_at'       => data_get($transaction->trx_data, 'due_at'),
            'remind'       => (bool) data_get($transaction->trx_data, 'remind', false),
            'reminded_at'  => data_get($transaction->trx_data, 'last_reminded_at'),
            'group_id'     => $group,
            'group'        => $group === null || $isPayersLeg ? null : $this->group($transaction, (string) $group),
            'counterparty' => $this->counterparties->for($transaction),
            'transaction'  => new TransactionResource($transaction),
        ];
    }

    /**
     * One bill, split: a request to each person, sharing a group id so the
     * asker's list shows them as one card with everyone's status.
     */
    public function split(Request $request): JsonResponse
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid) ? $this->wallet($request, $uuid) : null;

        $validated = $request->validate([
            'wallet_id'          => ['required', 'string'],
            'note'               => ['nullable', 'string', 'max:255'],
            'due_at'             => ['nullable', 'date', 'after_or_equal:today'],
            'remind'             => ['sometimes', 'boolean'],
            'people'             => ['required', 'array', 'min:1', 'max:20'],
            'people.*.recipient' => ['required', 'string', 'max:255'],
            'people.*.amount'    => AmountEntry::rules($wallet?->currency?->code ?? siteCurrency()),
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $group = (string) Str::uuid();

        $requests = DB::transaction(function () use ($request, $wallet, $validated, $group): array {
            $made = [];
            foreach ($validated['people'] as $person) {
                $legs = $this->requests->request($request->user(), [
                    'wallet_id' => $wallet->id,
                    'recipient' => $person['recipient'],
                    'amount'    => $person['amount'],
                    'note'      => $validated['note'] ?? null,
                ]);
                $this->stamp($legs, $validated['due_at'] ?? null, (bool) ($validated['remind'] ?? false), $group);
                $made[] = $legs['requester']->refresh();
            }

            return $made;
        });

        return ApiResponse::created([
            'group_id' => $group,
            'requests' => array_map(fn (TransactionModel $trx): array => $this->body($trx), $requests),
        ]);
    }

    /**
     * Nudge the payer about a request still waiting on them. Once a day per
     * request, so a nudge cannot become a nuisance.
     */
    public function remind(Request $request, string $reference): JsonResponse
    {
        $mine = TransactionModel::query()
            ->where('user_id', $request->user()->id)
            ->where('trx_id', $reference)
            ->where('trx_type', TrxType::REQUEST_MONEY)
            ->where('amount_flow', AmountFlow::PLUS)
            ->where('status', TrxStatus::PENDING)
            ->first();

        $payerLeg = $mine === null ? null : TransactionModel::query()->where('trx_id', (string) $mine->trx_reference)->with('user')->first();

        if ($mine === null || $payerLeg === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $last = data_get($mine->trx_data, 'last_reminded_at');
        if ($last !== null && Carbon::parse((string) $last)->diffInHours(now()) < 24) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('You already reminded them today.'));
        }

        $mine->update(['trx_data' => array_merge($mine->trx_data ?? [], ['last_reminded_at' => now()->toIso8601String()])]);
        $payerLeg->user?->notify(new MoneyRequestReminderNotification($payerLeg, (string) $request->user()->name));

        return ApiResponse::ok(['request' => $this->body($mine->refresh())]);
    }

    /**
     * The due date, the reminder wish and the split group, on both legs.
     *
     * @param array{requester: TransactionModel, payer: TransactionModel} $legs
     */
    private function stamp(array $legs, ?string $dueAt, bool $remind, ?string $group = null): void
    {
        $extra = array_filter([
            'due_at'   => $dueAt === null ? null : Carbon::parse($dueAt)->toDateString(),
            'remind'   => $remind ?: null,
            'group_id' => $group,
        ], fn ($v) => $v !== null);

        if ($extra === []) {
            return;
        }

        foreach ($legs as $leg) {
            $leg->update(['trx_data' => array_merge($leg->trx_data ?? [], $extra)]);
        }
    }

    /**
     * The other legs of a split: how many people, how many have paid.
     *
     * @return array{size: int, paid: int, people: list<array<string, mixed>>}
     */
    private function group(TransactionModel $transaction, string $group): array
    {
        $legs = TransactionModel::query()
            ->where('user_id', $transaction->user_id)
            ->where('trx_type', TrxType::REQUEST_MONEY)
            ->where('amount_flow', AmountFlow::PLUS)
            ->where('trx_data->group_id', $group)
            ->get();

        return [
            'size'   => $legs->count(),
            'paid'   => $legs->where('status', TrxStatus::COMPLETED)->count(),
            'people' => $legs->map(fn (TransactionModel $leg): array => [
                'reference' => $leg->trx_id,
                'status'    => $leg->status?->value,
                'amount'    => ApiMoney::amount($leg->payable_amount, $leg->payable_currency),
                'currency'  => $leg->payable_currency,
                'name'      => $this->counterparties->for($leg)['name'] ?? null,
            ])->values()->all(),
        ];
    }

    private function remarks(Request $request): ?string
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        return $validated['remarks'] ?? null;
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
            ->with(['currency'])
            ->first();
    }
}
