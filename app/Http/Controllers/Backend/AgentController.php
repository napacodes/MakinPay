<?php

namespace App\Http\Controllers\Backend;

use App\Enums\AgentOperationType;
use App\Enums\AgentStatus;
use App\Enums\TrxStatus;
use App\Http\Requests\Agent\AgentActionRequest;
use App\Models\Agent;
use App\Models\AgentCommissionRule;
use App\Models\AgentOperation;
use App\Models\Currency;
use App\Notifications\TemplateNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentController extends BaseController
{
    /**
     * How long a request may wait before the queue calls it late. The merchant
     * queue answers to the same figure, because the same people review both.
     */
    private const REVIEW_SLA_HOURS = 24;

    public static function permissions(): array
    {
        return [
            'index|pendingAgent|approvedAgent|rejectedAgent' => 'agent-list',
            'agentAction'                                    => 'agent-manage',
        ];
    }

    public function index(Request $request): View
    {
        return $this->agentScreen($request, null, __('Agent List'));
    }

    /**
     * The queue is not the list with a filter on it. An admin here is deciding,
     * not browsing: rows are oldest first, the wait is a column of its own, and
     * what the operator says they do — the thing the decision is actually made
     * on — is on the row instead of behind a modal.
     */
    public function pendingAgent(Request $request): View
    {
        $agents = $this->listing()
            ->where('status', AgentStatus::PENDING)
            ->filter($request)
            ->oldest()
            ->paginate(10)
            ->withQueryString();

        return view('backend.agent.pending', [
            'agents'          => $agents,
            'commissionRules' => $this->commissionRules(),
            'currencies'      => $this->filterCurrencies(),
            'title'           => __('Pending Agent'),
            'scope'           => AgentStatus::PENDING->value,
            'counts'          => $this->scopeCounts(),
            'queue'           => $this->pendingQueueStats(),
            'slaHours'        => self::REVIEW_SLA_HOURS,
        ]);
    }

    public function approvedAgent(Request $request): View
    {
        return $this->agentScreen($request, AgentStatus::APPROVED, __('Approved Agent'));
    }

    public function rejectedAgent(Request $request): View
    {
        return $this->agentScreen($request, AgentStatus::REJECTED, __('Rejected Agent'));
    }

    /**
     * The four scopes rendered one screen with a different title and nothing
     * else, and three of them hid the status filter — so which slice you were
     * looking at was unreachable from the page, and the Disabled slice was
     * unreachable from anywhere. They are tabs now, and every one of them
     * needs the same counts and totals beside it.
     */
    private function agentScreen(Request $request, ?AgentStatus $status, string $title): View
    {
        $agents = $this->listing()
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->filter($request)
            ->paginate(10)
            ->withQueryString();

        return view('backend.agent.index', [
            'agents'          => $agents,
            'commissionRules' => $this->commissionRules(),
            'currencies'      => $this->filterCurrencies(),
            'title'           => $title,
            'scope'           => $status?->value ?? 'all',
            'counts'          => $this->scopeCounts(),
            'stats'           => $this->agentStats(),
        ]);
    }

    /**
     * One query shape for all four screens, eager-loaded so a page of ten rows
     * is not a hundred queries.
     *
     * The two operation figures are counts on purpose. A cash-in/cash-out split
     * taken from summed amounts would be a ratio between two totals added up
     * across whatever currencies the counter happens to take, which is a number
     * nobody can act on. How many operations went each way means the same thing
     * in every currency.
     */
    private function listing(): Builder
    {
        $completed = fn (Builder $query): Builder => $query->where('status', TrxStatus::COMPLETED);

        return Agent::query()
            ->with(['user', 'currency', 'supportedCurrencies', 'commissionRuleAssignments.rule.currency'])
            ->withSum(['operations as completed_volume' => $completed], 'amount')
            ->withSum(['operations as earned_commission' => $completed], 'commission_amount')
            ->withCount([
                'operations as cash_in_count'  => fn (Builder $query) => $completed($query)->where('type', AgentOperationType::CASH_IN),
                'operations as cash_out_count' => fn (Builder $query) => $completed($query)->where('type', AgentOperationType::CASH_OUT),
            ]);
    }

    /**
     * One grouped query, not five counts. Deliberately unfiltered: a tab's
     * count says how big that slice is, not how much of it the current search
     * happens to match.
     *
     * @return array<string, int>
     */
    private function scopeCounts(): array
    {
        $byStatus = Agent::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                ($row->status instanceof AgentStatus ? $row->status->value : (string) $row->status) => (int) $row->aggregate,
            ]);

        $counts = ['all' => (int) $byStatus->sum()];

        foreach (AgentStatus::cases() as $case) {
            $counts[$case->value] = $byStatus->get($case->value, 0);
        }

        return $counts;
    }

    /**
     * Figures the tabs cannot express. Every one runs over the whole table
     * rather than the paginated page, so they do not change on page two.
     *
     * There is deliberately no headline volume here. An agent's operations are
     * taken in whatever currencies that counter supports, so one total across
     * the network would be a sum of unlike amounts wearing a single currency
     * code. Counts and rates mean the same thing in every currency, so those
     * are what the strip reports.
     *
     * @return array{pending:int, oldest_wait:string|null, live:int, live_share:int, operations:int, avg_commission:float|null}
     */
    private function agentStats(): array
    {
        $total    = Agent::query()->count();
        $live     = Agent::query()->where('status', AgentStatus::APPROVED)->count();
        $pending  = Agent::query()->where('status', AgentStatus::PENDING);
        $oldestAt = (clone $pending)->min('created_at');

        return [
            'pending'     => (clone $pending)->count(),
            'oldest_wait' => $oldestAt ? Carbon::parse($oldestAt)->diffForHumans(null, true) : null,
            'live'        => $live,
            'live_share'  => $total > 0 ? (int) round($live / $total * 100) : 0,
            'operations'  => AgentOperation::query()
                ->where('status', TrxStatus::COMPLETED)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'avg_commission' => Agent::query()
                ->where('status', AgentStatus::APPROVED)
                ->avg('commission'),
        ];
    }

    /**
     * Whole-queue figures, computed ahead of the paginator so page two reports
     * what page one reports.
     *
     * @return array{waiting:int, breaching:int, oldest_wait:string|null, no_description:int}
     */
    private function pendingQueueStats(): array
    {
        $pending  = Agent::query()->where('status', AgentStatus::PENDING);
        $oldestAt = (clone $pending)->min('created_at');

        return [
            'waiting'   => (clone $pending)->count(),
            'breaching' => (clone $pending)
                ->where('created_at', '<', now()->subHours(self::REVIEW_SLA_HOURS))
                ->count(),
            'oldest_wait'    => $oldestAt ? Carbon::parse($oldestAt)->diffForHumans(null, true) : null,
            'no_description' => (clone $pending)
                ->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))
                ->count(),
        ];
    }

    /**
     * Only the currencies some counter actually holds. The full table runs to
     * three figures, and a filter offering a currency no agent trades in can
     * only ever return nothing.
     *
     * @return array<string, string>
     */
    private function filterCurrencies(): array
    {
        return Currency::query()
            ->whereIn('id', fn ($query) => $query->select('currency_id')->from('agent_currencies'))
            ->orderBy('code')
            ->pluck('code', 'code')
            ->all();
    }

    public function agentAction(AgentActionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $agent       = Agent::query()->with(['currency', 'supportedCurrencies', 'user'])->findOrFail($validated['agent_id']);
        $action      = $validated['action'];
        $updateData  = [];
        $notifyUser  = (bool) setting('agent_user_email_notify', true);
        $rulesToSync = null;

        // Approve (also used for fee/commission updates on already-approved agents)
        if ($action === 'approve') {
            $updateData['commission'] = $validated['commission']       ?? $agent->commission;
            $rulesToSync              = $validated['commission_rules'] ?? [];

            if ($agent->status === AgentStatus::PENDING) {
                $updateData['status'] = AgentStatus::APPROVED;

                if ($notifyUser) {
                    $agent->user->notify(new TemplateNotification(
                        identifier: 'agent_user_notify_request_approved',
                        data: [
                            'agent_name' => $agent->agent_name,
                            'currencies' => $this->supportedCurrencyCodes($agent),
                        ],
                        action: route('user.agent.index', ['tab' => 'counter-cashout']),
                    ));
                }
            }
        }

        if ($action === 'reject' && $agent->status === AgentStatus::PENDING) {
            /*
             * One reason, read once. The e-mail and the row used to be able to
             * disagree — the notification interpolated whatever came in on the
             * request while the row kept nothing at all, so a rejected profile
             * could never say why. Both now come from this variable.
             */
            /*
             * A blank textarea arrives as null — TrimStrings and
             * ConvertEmptyStringsToNull are both in the default stack — so
             * there is nothing here to normalise.
             */
            $reason = $validated['rejection_reason'] ?? null;

            $updateData['status']           = AgentStatus::REJECTED;
            $updateData['rejection_reason'] = $reason;

            if ($notifyUser) {
                $agent->user->notify(new TemplateNotification(
                    identifier: 'agent_user_notify_request_rejected',
                    data: [
                        'agent_name'       => $agent->agent_name,
                        'currencies'       => $this->supportedCurrencyCodes($agent),
                        'rejection_reason' => $reason,
                    ],
                    action: route('user.agent.index', ['tab' => 'overview']),
                ));
            }
        }

        if ($action === 'disable' && $agent->status === AgentStatus::APPROVED) {
            $updateData['status'] = AgentStatus::DISABLED;
        }

        if ($action === 'enable' && $agent->status === AgentStatus::DISABLED) {
            $updateData['status'] = AgentStatus::APPROVED;
        }

        if (! empty($updateData)) {
            $agent->update($updateData);
        }

        if (is_array($rulesToSync)) {
            $this->syncCommissionRuleAssignments($agent, $rulesToSync);
        }

        notifyEvs('success', __('Agent updated successfully'));

        return back();
    }

    private function commissionRules(): EloquentCollection
    {
        return AgentCommissionRule::query()
            ->where('status', true)
            ->with('currency')
            ->orderBy('priority')
            ->orderBy('name')
            ->get();
    }

    private function supportedCurrencyCodes(Agent $agent): string
    {
        return $agent->supportedCurrencies->pluck('code')->implode(', ') ?: (string) $agent->currency?->code;
    }

    /**
     * @param array<string, array<string, mixed>> $rules
     */
    private function syncCommissionRuleAssignments(Agent $agent, array $rules): void
    {
        $selectedIds = collect($rules)
            ->filter(fn (array $rule): bool => (bool) ($rule['enabled'] ?? false))
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values();

        $validIds = AgentCommissionRule::query()
            ->where('status', true)
            ->whereIn('id', $selectedIds)
            ->pluck('id')
            ->all();

        $agent->commissionRuleAssignments()->delete();

        foreach ($validIds as $ruleId) {
            $ruleInput = $rules[(string) $ruleId] ?? $rules[$ruleId] ?? [];

            $agent->commissionRuleAssignments()->create([
                'agent_commission_rule_id' => $ruleId,
                'operation_type'           => $ruleInput['operation_type'] ?? 'all',
                'priority'                 => (int) ($ruleInput['priority'] ?? 100),
                'status'                   => true,
            ]);
        }
    }
}
