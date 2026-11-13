@extends('backend.user.manage')

@section('user_manage_content')
    @php
        $rangeUrl = fn (string $range): string => route('admin.user.manage', ['username' => $user->username, 'param' => 'statistics', 'range' => $range]);
        $ranges   = ['7d' => __('7 days'), '30d' => __('30 days'), '12m' => __('12 months')];
    @endphp

    @include('backend.user.partials._security_strip')

    {{--
        The KPI strip and the chart are painted from $overview here and
        repainted by user-manage.js from the same JSON when a range segment
        is picked (the segment links still work without it).
    --}}
    <section class="um-card" data-um-overview data-i18n-in="{{ __('In') }}" data-i18n-out="{{ __('Out') }}">
        <div class="um-card__head">
            <div>
                <h2 class="um-card__title">{{ __('Transaction summary') }}</h2>
                <p class="um-card__sub">{{ __('Volume in and out across all wallets') }}</p>
            </div>
            <nav class="um-seg" aria-label="{{ __('Range') }}">
                @foreach($ranges as $key => $label)
                    <a href="{{ $rangeUrl($key) }}" class="um-seg__btn {{ $overview['range'] === $key ? 'is-active' : '' }}" data-um-range="{{ $key }}" @if($overview['range'] === $key) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </div>

        <div class="um-kpis">
            <div class="um-kpi">
                <span class="um-kpi__label"><span class="um-kpi__swatch"></span>{{ __('Money in') }}</span>
                <span class="um-kpi__value um-kpi__value--in" data-um-kpi="in_total">{{ $overview['in_total'] }}</span>
                <span class="um-kpi__note" data-um-kpi="in_note">{{ $overview['in_note'] }}</span>
            </div>
            <div class="um-kpi">
                <span class="um-kpi__label"><span class="um-kpi__swatch um-kpi__swatch--out"></span>{{ __('Money out') }}</span>
                <span class="um-kpi__value" data-um-kpi="out_total">{{ $overview['out_total'] }}</span>
                <span class="um-kpi__note" data-um-kpi="out_note">{{ $overview['out_note'] }}</span>
            </div>
            <div class="um-kpi">
                <span class="um-kpi__label">{{ __('Net flow') }}</span>
                <span class="um-kpi__value um-kpi__value--net" data-um-kpi="net">{{ $overview['net'] }}</span>
                <span class="um-kpi__note" data-um-kpi="net_note">{{ $overview['net_note'] }}</span>
            </div>
        </div>

        <div class="um-chart" style="--um-buckets: {{ count($overview['bars']) }}" role="img" aria-label="{{ __('Money in and out per period') }}">
            <div class="um-chart__axis" data-um-chart-axis>
                @foreach($overview['ticks'] as $tick)
                    <span>{{ $tick }}</span>
                @endforeach
            </div>
            <div class="um-chart__plot">
                <div class="um-chart__grid" data-um-chart-grid>
                    @foreach($overview['ticks'] as $tick)
                        <span class="{{ $loop->last ? 'is-base' : '' }}"></span>
                    @endforeach
                </div>
                <div class="um-chart__bars" data-um-chart-bars>
                    @foreach($overview['bars'] as $bar)
                        <div class="um-chart__group {{ $bar['current'] ? 'is-current' : '' }}" title="{{ $bar['tip'] }}">
                            <span class="um-chart__bar" style="height: {{ $bar['in'] }}%"></span>
                            <span class="um-chart__bar um-chart__bar--out" style="height: {{ $bar['out'] }}%"></span>
                        </div>
                    @endforeach
                </div>
            </div>
            <span></span>
            <div class="um-chart__labels" data-um-chart-labels>
                @foreach($overview['bars'] as $bar)
                    <span class="{{ $bar['current'] ? 'is-current' : '' }}">{{ $bar['label'] }}</span>
                @endforeach
            </div>
        </div>
    </section>

    <section class="um-card" data-um-recent>
        <div class="um-card__head">
            <h2 class="um-card__title">{{ __('Recent transactions') }}</h2>
            <a href="{{ route('admin.user.manage', ['username' => $user->username, 'param' => 'transactions']) }}" class="um-link">{{ __('View all') }}</a>
        </div>

        @forelse($recent as $transaction)
            @php
                $isIn   = $transaction->amount_flow === \App\Enums\AmountFlow::PLUS;
                $failed = in_array($transaction->status, [\App\Enums\TrxStatus::FAILED, \App\Enums\TrxStatus::CANCELED], true);
            @endphp
            {{-- Opens the same detail modal the Transactions tab does. --}}
            <div class="um-trx @can('transaction-list') um-trx--open @endcan"
                 @can('transaction-list')
                     data-trx-row
                     data-trx-url="{{ route('admin.transaction.show', $transaction->trx_id) }}"
                     role="button"
                     tabindex="0"
                     aria-label="{{ __('Open transaction :id', ['id' => $transaction->trx_id]) }}"
                 @endcan>
                <span class="um-tile {{ $isIn ? 'um-tile--in' : ($transaction->trx_type === \App\Enums\TrxType::WITHDRAW ? 'um-tile--out' : 'um-tile--brand') }}"><x-icon :name="$transaction->trx_type->icon()"/></span>
                <span class="um-trx__copy">
                    <span class="um-trx__title">{{ $transaction->description ?: $transaction->trx_type->label() }}</span>
                    <span class="um-trx__meta"><span class="um-mono" dir="ltr">{{ $transaction->trx_id }}</span> · {{ $transaction->created_at->diffForHumans() }}@if($transaction->provider) · {{ $transaction->provider }}@endif</span>
                </span>
                <span class="um-trx__end">
                    <span class="um-amount {{ $failed ? 'um-amount--muted' : ($isIn ? 'um-amount--in' : '') }}">{{ $transaction->amount_flow->sign($transaction->status) }}{{ money($transaction->amount, $transaction->currency) }}</span>
                    <span class="um-badge um-badge--xs um-badge--{{ match ($transaction->status) { \App\Enums\TrxStatus::COMPLETED => 'success', \App\Enums\TrxStatus::PENDING => 'warning', default => 'danger' } }}">{{ $transaction->status->label() }}</span>
                </span>
            </div>
        @empty
            <div class="um-card__empty">{{ __('No transactions yet.') }}</div>
        @endforelse
    </section>

    {{-- Outside the card on purpose: a transform or filter on an ancestor
         becomes the containing block for a fixed dialogue, which is how the
         settings dialogues once ended up trapped at 0x0. --}}
    @include('backend.user.partials._transaction_detail')
@endsection
