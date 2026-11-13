@extends('backend.user.manage')

@section('user_manage_content')
    @php
        $base   = ['username' => $user->username, 'param' => 'transactions'];
        $keep   = request()->only(['search', 'daterange']);
        $groups = ['all' => __('All'), 'deposit' => __('Deposit'), 'withdraw' => __('Withdrawal'), 'transfers' => __('Transfers')];
        $exportQuery = array_filter(['user_id' => $user->id, 'search' => request('search'), 'daterange' => request('daterange')]);
    @endphp

    <section class="um-card" data-um-transactions>
        <form method="GET" action="{{ route('admin.user.manage', $base) }}" class="um-toolbar">
            <input type="hidden" name="type" value="{{ $typeGroup }}">
            <input type="hidden" name="daterange" value="{{ request('daterange') }}" data-um-daterange-value>
            <label class="um-search">
                <span class="um-search__icon"><x-icon name="search"/></span>
                <input type="search" name="search" class="um-input" value="{{ request('search') }}" placeholder="{{ __('Search by ID, description, provider') }}">
            </label>
            <nav class="um-seg" aria-label="{{ __('Transaction type') }}">
                @foreach($groups as $key => $label)
                    <a href="{{ route('admin.user.manage', $base + $keep + ['type' => $key]) }}" class="um-seg__btn {{ $typeGroup === $key ? 'is-active' : '' }}" @if($typeGroup === $key) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <div class="um-toolbar__end">
                <button type="button" class="um-btn" data-um-daterange data-i18n-all="{{ __('All time') }}">{{ request('daterange') ?: __('All time') }}</button>
                @can('transaction-list')
                    <a href="{{ route('admin.transaction.export', $exportQuery) }}" class="um-btn">{{ __('Export') }}</a>
                @endcan
            </div>
        </form>

        <div class="um-table-wrap">
            <div class="um-table__head">
                <span>{{ __('Transaction') }}</span>
                <span>{{ __('Reference') }}</span>
                <span>{{ __('Status') }}</span>
                <span class="um-table__cell--end">{{ __('Amount') }}</span>
            </div>

            @foreach($transactions as $transaction)
                @php
                    $isIn   = $transaction->amount_flow === \App\Enums\AmountFlow::PLUS;
                    $failed = in_array($transaction->status, [\App\Enums\TrxStatus::FAILED, \App\Enums\TrxStatus::CANCELED], true);
                    $tone   = match ($transaction->status) {
                        \App\Enums\TrxStatus::COMPLETED => 'success',
                        \App\Enums\TrxStatus::PENDING   => 'warning',
                        default                          => 'danger',
                    };
                @endphp
                {{--
                    The row opens the ledger's own detail modal. `tabindex`
                    and the role are what make it reachable without a mouse:
                    a div is not a button, so the script's keydown handler
                    supplies Enter and Space, but only to a row that can hold
                    focus in the first place.
                --}}
                <div class="um-table__row @can('transaction-list') um-table__row--open @endcan"
                     @can('transaction-list')
                         data-trx-row
                         data-trx-url="{{ route('admin.transaction.show', $transaction->trx_id) }}"
                         role="button"
                         tabindex="0"
                         aria-label="{{ __('Open transaction :id', ['id' => $transaction->trx_id]) }}"
                     @endcan>
                    <span class="um-cell">
                        <span class="um-tile um-tile--sm {{ $isIn ? 'um-tile--in' : ($transaction->trx_type === \App\Enums\TrxType::WITHDRAW ? 'um-tile--out' : 'um-tile--brand') }}"><x-icon :name="$transaction->trx_type->icon()"/></span>
                        <span class="um-cell__copy">
                            <span class="um-cell__title">{{ $transaction->description ?: $transaction->trx_type->label() }}</span>
                            <span class="um-cell__meta">{{ $transaction->trx_type->label() }}@if($transaction->provider) · {{ $transaction->provider }}@endif</span>
                        </span>
                    </span>
                    <span class="um-cell__stack">
                        <span class="um-cell__ref" dir="ltr">{{ $transaction->trx_id }}</span>
                        <span class="um-cell__date">{{ $transaction->created_at->format('M d, H:i') }}</span>
                    </span>
                    <span><span class="um-badge um-badge--{{ $tone }}"><span class="um-badge__dot"></span>{{ $transaction->status->label() }}</span></span>
                    <span class="um-cell__end">
                        <span class="um-amount {{ $failed ? 'um-amount--muted' : ($isIn ? 'um-amount--in' : '') }}">{{ $transaction->amount_flow->sign($transaction->status) }}{{ money($transaction->amount, $transaction->currency) }}</span>
                        <span class="um-fee">{{ __('Fee :fee', ['fee' => money($transaction->fee, $transaction->currency)]) }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        @if($transactions->isEmpty())
            <div class="um-card__empty">{{ __('No transactions match these filters.') }}</div>
        @endif

        <div class="um-pager">
            <span>{{ __('Showing') }} <strong>{{ $transactions->count() }}</strong> {{ __('of :total', ['total' => $transactions->total()]) }}</span>
            <div class="um-pager__nav">
                <a class="um-btn um-btn--sm" href="{{ $transactions->previousPageUrl() ?? '#' }}" @if($transactions->onFirstPage()) aria-disabled="true" tabindex="-1" @endif>{{ __('Previous') }}</a>
                <a class="um-btn um-btn--sm" href="{{ $transactions->nextPageUrl() ?? '#' }}" @unless($transactions->hasMorePages()) aria-disabled="true" tabindex="-1" @endunless>{{ __('Next') }}</a>
            </div>
        </div>
    </section>

    @include('backend.user.partials._transaction_detail')
@endsection
