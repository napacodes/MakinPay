<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\AmountFlow;
use App\Enums\LoginRisk;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\VirtualCard\VirtualCardStatus;
use App\Models\Budget;
use App\Models\LoginActivity;
use App\Models\P2P\Order;
use App\Models\ScheduledPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use App\Services\Api\Budgets\BudgetService;
use App\Services\P2P\P2POrderState;
use App\Support\ApiMoney;
use Illuminate\Database\Eloquent\Builder;

/**
 * What is waiting on the customer, for the home screen's action center.
 *
 * Facts only, and only the ones with a button: a request somebody made of
 * them, a trade whose next step is theirs, a card they froze. Each list is
 * capped at three - the screen shows cards, not a queue, and the module's own
 * screen has the rest. Every item carries the key the app already acts with
 * (the request's reference, the order's id, the card's id), so the buttons
 * here call the same endpoints as the buttons on those screens.
 */
class DashboardAttention
{
    private const LIMIT = 3;

    public function __construct(private readonly P2POrderState $states, private readonly BudgetService $budgets) {}

    /**
     * @return array{money_requests: list<array<string, mixed>>, p2p_orders: list<array<string, mixed>>, frozen_cards: list<array<string, mixed>>}
     */
    public function for(User $user): array
    {
        return [
            'money_requests'   => $this->moneyRequests($user),
            'p2p_orders'       => $this->p2pOrders($user),
            'frozen_cards'     => $this->frozenCards($user),
            'new_devices'      => $this->newDevices($user),
            'budget_warnings'  => $this->budgetWarnings($user),
            'failed_schedules' => $this->failedSchedules($user),
        ];
    }

    /**
     * Pending requests where this customer is the one asked to pay - the
     * payer's leg, which is the MINUS row. The asker's name is on the other
     * leg, reached through trx_reference.
     *
     * @return list<array<string, mixed>>
     */
    private function moneyRequests(User $user): array
    {
        $legs = Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::REQUEST_MONEY)
            ->where('amount_flow', AmountFlow::MINUS)
            ->where('status', TrxStatus::PENDING)
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        $askers = Transaction::query()
            ->whereIn('trx_id', $legs->pluck('trx_reference')->filter())
            ->with('user')
            ->get()
            ->keyBy('trx_id');

        return $legs->map(function (Transaction $leg) use ($askers): array {
            $currency = $leg->payable_currency ?? $leg->currency;

            return [
                'reference'  => $leg->trx_id,
                'from'       => $askers->get($leg->trx_reference)?->user?->name,
                'amount'     => ApiMoney::amount($leg->payable_amount ?? $leg->amount, $currency),
                'currency'   => $currency,
                'decimals'   => ApiMoney::decimals($currency),
                'note'       => $leg->remarks,
                'created_at' => $leg->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Open trades whose next step is this customer's: pay as the buyer, or
     * release as the seller. Decided by the same state service the trade
     * room uses, so the card and the room cannot disagree about whose turn
     * it is.
     *
     * @return list<array<string, mixed>>
     */
    private function p2pOrders(User $user): array
    {
        $orders = Order::query()
            ->where(fn (Builder $query) => $query->where('maker_id', $user->id)->orWhere('taker_id', $user->id))
            ->whereIn('status', ['PENDING', 'PAID'])
            ->with(['offer.quoteCurrency', 'wallet.currency', 'maker', 'taker'])
            ->latest('id')
            ->limit(10)
            ->get();

        $items = [];

        foreach ($orders as $order) {
            $state  = $this->states->for($order, (int) $user->id);
            $action = $state['can_release'] ? 'release' : ($state['can_mark_paid'] ? 'pay' : null);

            if ($action === null) {
                continue;
            }

            $asset        = $order->wallet?->currency?->code;
            $fiat         = $order->offer?->quoteCurrency?->code;
            $counterparty = (int) $order->maker_id === (int) $user->id ? $order->taker : $order->maker;

            $items[] = [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'action'       => $action,
                'role'         => $state['role'],
                'side'         => $order->offer?->side->value,
                'asset'        => $asset,
                'amount'       => ApiMoney::amount((string) $order->amount, $asset),
                'fiat'         => $fiat,
                'total'        => ApiMoney::amount((string) $order->total, $fiat),
                'counterparty' => $counterparty?->name,
                'expires_at'   => $order->expires_at?->toIso8601String(),
            ];

            if (count($items) === self::LIMIT) {
                break;
            }
        }

        return $items;
    }

    /**
     * Cards the customer has paused. Inactive is the frozen status - the
     * same reading VirtualCardController::card makes.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Budgets past their alert line this period - the same card the push
     * offered, on Home until the period ends or the limit is raised.
     */
    private function budgetWarnings(User $user): array
    {
        $display = $user->displayCurrency();

        return $user->budgets()->get()
            ->map(fn (Budget $b): array => $this->budgets->describe($user, $b, $display))
            ->filter(fn (array $item): bool => $item['status'] !== 'ok')
            ->sortByDesc('ratio')
            ->take(self::LIMIT)
            ->map(fn (array $item): array => ['id' => $item['id'], 'category' => $item['category'], 'ratio' => $item['ratio'], 'left' => $item['left'], 'over' => $item['over'], 'limit' => $item['limit'], 'spent' => $item['spent'], 'currency' => $item['currency'], 'days_left' => $item['days_left'], 'status' => $item['status']])
            ->values()
            ->all();
    }

    /**
     * Scheduled payments whose last run did not go through and are still
     * waiting on a retry or on the customer.
     */
    private function failedSchedules(User $user): array
    {
        return $user->scheduledPayments()
            ->where('status', 'active')
            ->where('last_status', 'failed')
            ->latest('last_run_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ScheduledPayment $s): array => [
                'id'       => $s->id,
                'name'     => $s->note ?: ($s->recipient_name ?: __('Scheduled payment')),
                'amount'   => ApiMoney::amount((string) $s->amount, (string) $s->currency),
                'currency' => $s->currency,
                'reason'   => $s->failed_reason,
                'retry_at' => $s->next_run_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Sign-ins the risk rules flagged as a new device or place in the last
     * three days, until the customer says they were theirs.
     *
     * @return list<array<string, mixed>>
     */
    private function newDevices(User $user): array
    {
        return LoginActivity::query()
            ->where('user_id', $user->id)
            ->where('succeeded', true)
            ->whereIn('risk', [LoginRisk::NEW_DEVICE->value, LoginRisk::SUSPICIOUS->value])
            ->whereNull('acknowledged_at')
            ->where('login_at', '>=', now()->subDays(3))
            ->latest('login_at')
            ->limit(3)
            ->get()
            ->map(fn (LoginActivity $row): array => [
                'id'       => $row->id,
                'device'   => implode(' · ', array_filter([$row->device !== 'Unknown' ? $row->device : null, $row->platform !== 'Unknown' ? $row->platform : null, $row->browser !== 'Unknown' ? $row->browser : null])) ?: null,
                'location' => $row->location(),
                'at'       => ($row->login_at ?? $row->created_at)?->toIso8601String(),
                'reason'   => $row->risk_reason,
            ])
            ->values()
            ->all();
    }

    private function frozenCards(User $user): array
    {
        return VirtualCard::query()
            ->where('user_id', $user->id)
            ->where('status', VirtualCardStatus::Inactive)
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (VirtualCard $card): array => [
                'id'      => $card->id,
                'last4'   => $card->last4,
                'network' => $card->network?->value,
            ])
            ->values()
            ->all();
    }
}
