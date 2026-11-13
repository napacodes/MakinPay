<?php

namespace App\Http\Controllers\Backend;

use App\Enums\AgentCommissionRuleType;
use App\Enums\AgentOperationType;
use App\Http\Requests\Agent\AgentCommissionRuleRequest;
use App\Models\AgentCommissionRule;
use App\Models\AgentCommissionRuleAssignment;
use App\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AgentCommissionRuleController extends BaseController
{
    public static function permissions(): array
    {
        return [
            '*' => 'agent-commission-rules-manage',
        ];
    }

    public function index(): View
    {
        $title = __('Agent Commission Rules');
        $rules = AgentCommissionRule::query()
            ->with('currency')
            ->withCount('assignments')
            ->orderBy('priority')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $currencies = Currency::query()
            ->where('status', true)
            ->orderBy('code')
            ->get();

        $operationTypes   = ['all' => __('All Operations')] + AgentOperationType::options();
        $calculationTypes = AgentCommissionRuleType::options();
        $ruleStats        = $this->ruleStats();
        $rangeCeiling     = $this->rangeCeiling();

        return view('backend.agent.commission-rules.index', compact(
            'calculationTypes',
            'currencies',
            'operationTypes',
            'rangeCeiling',
            'ruleStats',
            'rules',
            'title'
        ));
    }

    /**
     * Whole-table figures for the header strip, computed ahead of the
     * paginator so page two reports what page one reports.
     *
     * "Never matched" is the one worth drawing: a rule that is active, is not
     * a global fallback and has no assignment can never fire, and nothing on
     * the screen said so before.
     *
     * @return array{total:int, active:int, global:int, assigned_agents:int, unused:int}
     */
    private function ruleStats(): array
    {
        $active = AgentCommissionRule::query()->where('status', true);

        return [
            'total'           => AgentCommissionRule::query()->count(),
            'active'          => (clone $active)->count(),
            'global'          => (clone $active)->where('applies_globally', true)->count(),
            'assigned_agents' => AgentCommissionRuleAssignment::query()->distinct()->count('agent_id'),
            'unused'          => (clone $active)
                ->where('applies_globally', false)
                ->doesntHave('assignments')
                ->count(),
        ];
    }

    /**
     * The widest ceiling any rule declares, so each row's band can be drawn
     * against the same scale — a narrow rule should look narrow beside a broad
     * one rather than reading identically to it.
     */
    private function rangeCeiling(): float
    {
        return (float) (AgentCommissionRule::query()->max('max_amount') ?? 0);
    }

    public function store(AgentCommissionRuleRequest $request): RedirectResponse
    {
        AgentCommissionRule::query()->create($this->payload($request));

        notifyEvs('success', __('Commission rule created successfully.'));

        return to_route('admin.agent.commission-rules.index');
    }

    public function update(AgentCommissionRuleRequest $request, AgentCommissionRule $commissionRule): RedirectResponse
    {
        $commissionRule->update($this->payload($request));

        notifyEvs('success', __('Commission rule updated successfully.'));

        return to_route('admin.agent.commission-rules.index');
    }

    public function destroy(AgentCommissionRule $commissionRule): RedirectResponse
    {
        $commissionRule->delete();

        notifyEvs('success', __('Commission rule deleted successfully.'));

        return to_route('admin.agent.commission-rules.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AgentCommissionRuleRequest $request): array
    {
        $data                     = $request->validated();
        $data['status']           = $request->boolean('status');
        $data['applies_globally'] = $request->boolean('applies_globally');

        if ($data['calculation_type'] === AgentCommissionRuleType::PERCENTAGE->value) {
            $data['fixed_amount'] = 0;
        }

        if ($data['calculation_type'] === AgentCommissionRuleType::FIXED->value) {
            $data['percentage_rate'] = 0;
        }

        return $data;
    }
}
