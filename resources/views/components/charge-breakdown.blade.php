@props(['method'])

@once
    <style>
        .charge-chips {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }
        .charge-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            line-height: 1.1;
            white-space: nowrap;
            cursor: default;
        }
        .charge-chip > i { font-size: 11px; opacity: .95; }
        .charge-chip--user { background: rgba(70, 99, 238, .10); color: #3a55d9; }
        .charge-chip--merchant { background: rgba(25, 135, 84, .11); color: #157347; }
        .charge-chip--agent { background: rgba(201, 138, 27, .14); color: #9c6b12; }
        .charge-chip__star { font-size: 9px; vertical-align: super; opacity: .7; margin-left: 1px; }
    </style>
@endonce

@php
    use App\Support\Money;

    $symbol = $method->currency_symbol;

    // A charge can now have both components, so describe whichever are set
    // rather than branching on a single type. The `$type` argument is kept so
    // any caller still passing one does not break.
    $formatCharge = function ($amount, $type, $fixed = null, $percent = null) use ($symbol): string {
        if ($fixed !== null || $percent !== null) {
            return Money::describeFee($fixed, $percent, $symbol);
        }

        $isPercent = $type && (is_object($type) ? $type->isPercent() : $type === 'percent');

        return $isPercent ? (($amount ?? 0).'%') : ($symbol.($amount ?? 0));
    };

    $agentSet    = $method->agent_charge !== null;
    $agentAmount = $agentSet ? $method->agent_charge : $method->user_charge;
    $agentType   = $agentSet ? $method->agent_charge_type : $method->user_charge_type;
    $agentTitle  = $agentSet ? __('Agent') : __('Agent (inherits user rate)');
    $agentPrefix = $agentSet ? 'agent_' : 'user_';
    $agentFixed   = $method->getAttributes()[$agentPrefix.'charge_fixed'] ?? null;
    $agentPercent = $method->getAttributes()[$agentPrefix.'charge_percent'] ?? null;
@endphp

<div class="charge-chips">
    <span class="charge-chip charge-chip--user" title="{{ __('User') }}">
        <i class="fas fa-user"></i>{{ $formatCharge($method->user_charge, $method->user_charge_type, $method->getAttributes()['user_charge_fixed'] ?? null, $method->getAttributes()['user_charge_percent'] ?? null) }}
    </span>
    <span class="charge-chip charge-chip--merchant" title="{{ __('Merchant') }}">
        <i class="fas fa-store"></i>{{ $formatCharge($method->merchant_charge, $method->merchant_charge_type, $method->getAttributes()['merchant_charge_fixed'] ?? null, $method->getAttributes()['merchant_charge_percent'] ?? null) }}
    </span>
    <span class="charge-chip charge-chip--agent" title="{{ $agentTitle }}">
        <i class="fas fa-user-tie"></i>{{ $formatCharge($agentAmount, $agentType, $agentFixed, $agentPercent) }}@unless($agentSet)<span class="charge-chip__star">*</span>@endunless
    </span>
</div>
