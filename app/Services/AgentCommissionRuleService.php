<?php

namespace App\Services;

use App\Data\CommissionResult;
use App\Enums\AgentCommissionRuleType;
use App\Enums\AgentOperationType;
use App\Models\Agent;
use App\Models\AgentCommissionRule;
use App\Models\AgentCommissionRuleAssignment;
use App\Support\DecimalMoney;
use App\Support\FeeCalculator;
use Illuminate\Support\Collection;

class AgentCommissionRuleService
{
    /**
     * `string|int|float`, so the exact figure reaches FeeCalculator.
     *
     * The agent service computes its operation amount as a decimal string;
     * this declared `float` and narrowed it straight back, so the commission
     * was worked out against a principal the operation never used. The
     * arithmetic underneath was never the problem - FeeCalculator already
     * takes a string and works in decimal - the signatures in between were,
     * exactly as they were for subtractMoney() one layer down.
     *
     * The difference survives FeeCalculator's own float return, which is why
     * this is worth doing rather than tidy: 2.5% of 999999999.12345678 is
     * 24999999.9780864082 exact and 24999999.9780864194 through a double,
     * about 1.1e-8 - above the eighth place a wallet holds.
     */
    public function calculate(Agent $agent, AgentOperationType $operationType, string|int|float $amount, ?int $currencyId = null): CommissionResult
    {
        // Compared as a decimal. `max(0, $amount)` and `<= 0` both coerce a
        // string back to the double this signature exists to avoid.
        if (DecimalMoney::compare($amount, '0') <= 0) {
            return CommissionResult::none();
        }

        $currencyId ??= (int) $agent->currency_id;

        $assignment = $this->matchingAssignment($agent, $operationType, $amount, $currencyId);
        if ($assignment?->rule) {
            return $this->fromRule($assignment->rule, $agent, $operationType, $amount, $currencyId, 'assigned_rule', $assignment);
        }

        $rule = $this->matchingGlobalRule($agent, $operationType, $amount, $currencyId);
        if ($rule) {
            return $this->fromRule($rule, $agent, $operationType, $amount, $currencyId, 'global_rule');
        }

        $agentRate = max(0, (float) $agent->commission);
        if ($agentRate > 0) {
            return $this->fromPercentageFallback('agent_profile', $agentRate, $amount, $currencyId);
        }

        $defaultRate = max(0, (float) setting('agent_default_commission', 0));
        if ($defaultRate > 0) {
            return $this->fromPercentageFallback('global_default', $defaultRate, $amount, $currencyId);
        }

        return CommissionResult::none();
    }

    private function matchingAssignment(Agent $agent, AgentOperationType $operationType, string|int|float $amount, int $currencyId): ?AgentCommissionRuleAssignment
    {
        /** @var Collection<int, AgentCommissionRuleAssignment> $assignments */
        $assignments = AgentCommissionRuleAssignment::query()
            ->where('agent_id', $agent->id)
            ->where('status', true)
            ->whereIn('operation_type', ['all', $operationType->value])
            ->with('rule')
            ->get()
            ->filter(fn (AgentCommissionRuleAssignment $assignment): bool => $assignment->rule !== null
                && $this->ruleMatches($assignment->rule, $operationType, $amount, $currencyId));

        return $assignments
            ->sort(function (AgentCommissionRuleAssignment $left, AgentCommissionRuleAssignment $right) use ($currencyId, $operationType): int {
                $scoreComparison = $this->assignmentScore($right, $currencyId, $operationType)
                    <=> $this->assignmentScore($left, $currencyId, $operationType);

                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                $priorityComparison = $left->priority <=> $right->priority;
                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                return $right->id <=> $left->id;
            })
            ->first();
    }

    private function matchingGlobalRule(Agent $agent, AgentOperationType $operationType, string|int|float $amount, int $currencyId): ?AgentCommissionRule
    {
        /** @var Collection<int, AgentCommissionRule> $rules */
        $rules = AgentCommissionRule::query()
            ->where('status', true)
            ->where('applies_globally', true)
            ->whereIn('operation_type', ['all', $operationType->value])
            ->where(function ($query) use ($currencyId) {
                $query->whereNull('currency_id')->orWhere('currency_id', $currencyId);
            })
            ->where('min_amount', '<=', $amount)
            ->where(function ($query) use ($amount) {
                $query->whereNull('max_amount')->orWhere('max_amount', '>=', $amount);
            })
            ->where(function ($query) {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', now());
            })
            ->get();

        return $rules
            ->sort(function (AgentCommissionRule $left, AgentCommissionRule $right) use ($currencyId, $operationType): int {
                $scoreComparison = $this->ruleScore($right, $currencyId, $operationType)
                    <=> $this->ruleScore($left, $currencyId, $operationType);

                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                $priorityComparison = $left->priority <=> $right->priority;
                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                return $right->id <=> $left->id;
            })
            ->first();
    }

    private function ruleMatches(AgentCommissionRule $rule, AgentOperationType $operationType, string|int|float $amount, int $currencyId): bool
    {
        if (! $rule->status) {
            return false;
        }

        if (! in_array($rule->operation_type, ['all', $operationType->value], true)) {
            return false;
        }

        if ($rule->currency_id !== null && (int) $rule->currency_id !== $currencyId) {
            return false;
        }

        // Decimal comparisons: a float cast here reintroduces the narrowing
        // one line after the signature removed it.
        //
        // Reverting these two survives mutation, and honestly so: a bound is a
        // figure an administrator typed, so for the comparison to differ they
        // would have to enter one agreeing with the principal to sixteen
        // significant digits and disagreeing at the seventeenth. The admin
        // form will not take it. Kept because it is correct and free, not
        // because a test is watching.
        if (DecimalMoney::compare($rule->min_amount ?? 0, $amount) > 0) {
            return false;
        }

        if ($rule->max_amount !== null && DecimalMoney::compare($rule->max_amount, $amount) < 0) {
            return false;
        }

        if ($rule->effective_from !== null && $rule->effective_from->isFuture()) {
            return false;
        }

        if ($rule->effective_until !== null && $rule->effective_until->isPast()) {
            return false;
        }

        return true;
    }

    private function fromRule(
        AgentCommissionRule $rule,
        Agent $agent,
        AgentOperationType $operationType,
        string|int|float $amount,
        int $currencyId,
        string $source,
        ?AgentCommissionRuleAssignment $assignment = null,
    ): CommissionResult {
        // Existing PERCENTAGE and FIXED rules keep their exact meaning; only
        // COMBINED uses both components. Routing all three through
        // FeeCalculator keeps the min/max clamping identical to every other
        // fee in the platform, and caps the commission at the operation
        // amount — a commission larger than the operation is nonsensical, and
        // Marketplace\CommissionCalculator already capped this way.
        $commission = app(FeeCalculator::class)->total(
            $amount,
            fixed: $rule->calculation_type   === AgentCommissionRuleType::PERCENTAGE ? 0 : $rule->fixed_amount,
            percent: $rule->calculation_type === AgentCommissionRuleType::FIXED ? 0 : $rule->percentage_rate,
            min: $rule->min_commission,
            max: $rule->max_commission,
        );

        $commission = round($commission, $this->decimalPlaces());

        return new CommissionResult(
            ruleId: $rule->id,
            source: $source,
            calculationType: $rule->calculation_type,
            percentageRate: (float) $rule->percentage_rate,
            fixedAmount: (float) $rule->fixed_amount,
            amount: $commission,
            snapshot: [
                'source'               => $source,
                'rule_id'              => $rule->id,
                'rule_name'            => $rule->name,
                'assignment_id'        => $assignment?->id,
                'assignment_operation' => $assignment?->operation_type,
                'assignment_priority'  => $assignment?->priority,
                'operation_type'       => $operationType->value,
                'agent_id'             => $agent->id,
                'currency_id'          => $currencyId,
                'calculation_type'     => $rule->calculation_type->value,
                'percentage_rate'      => (float) $rule->percentage_rate,
                'fixed_amount'         => (float) $rule->fixed_amount,
                'min_commission'       => $rule->min_commission,
                'max_commission'       => $rule->max_commission,
                'amount'               => $commission,
            ],
        );
    }

    private function fromPercentageFallback(string $source, float $rate, string|int|float $amount, int $currencyId): CommissionResult
    {
        $commission = app(FeeCalculator::class)->total($amount, 0, $rate, scale: $this->decimalPlaces());

        return new CommissionResult(
            ruleId: null,
            source: $source,
            calculationType: AgentCommissionRuleType::PERCENTAGE,
            percentageRate: $rate,
            fixedAmount: 0,
            amount: $commission,
            snapshot: [
                'source'           => $source,
                'currency_id'      => $currencyId,
                'calculation_type' => AgentCommissionRuleType::PERCENTAGE->value,
                'percentage_rate'  => $rate,
                'fixed_amount'     => 0,
                'amount'           => $commission,
            ],
        );
    }

    private function assignmentScore(AgentCommissionRuleAssignment $assignment, int $currencyId, AgentOperationType $operationType): int
    {
        return (int) ($assignment->operation_type === $operationType->value) * 50
            + ($assignment->rule ? $this->ruleScore($assignment->rule, $currencyId, $operationType) : 0);
    }

    private function ruleScore(AgentCommissionRule $rule, int $currencyId, AgentOperationType $operationType): int
    {
        return (int) ((int) $rule->currency_id === $currencyId)       * 20
            + (int) ($rule->operation_type === $operationType->value) * 10
            + (int) ($rule->max_amount !== null);
    }

    private function decimalPlaces(): int
    {
        return max(2, min(8, (int) setting('site_decimal', 2)));
    }
}
