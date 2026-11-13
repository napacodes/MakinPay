@extends('backend.layouts.app')
@section('title', __('Transactions'))
@section('content')

    {{-- `$headerStats` renders as the cards below, where each count is also the
         filter that produces it. Passing the same four numbers to the header's
         own stat row as well would print them twice on one screen. --}}
    <x-page-header
        :title="__(':type Transactions', ['type' => request('type') == 'all' ? __('All') : title(request('type'))])"
        :subtitle="__('Every movement recorded across user wallets. Filters narrow both the summary and the list.')"
        icon="fa-receipt"
        :stats="[]"
        class="mb-3"
    >
        <x-slot:actions>
            <div class="admin-trx-topbar">
                {{-- `#reportrange` keeps its id so the shared picker in
                     `_scripts.blade.php` still binds. The hidden `daterange`
                     input it writes to stays inside the filter form below,
                     which is what actually submits.

                     The wrapper is a `div`, as it is on every other page that
                     uses this picker, because the shared handler writes the
                     range with `$('#reportrange span').text(...)`. jQuery sets
                     text on *every* match, and a `span` wrapper is a match — so
                     it emptied itself, taking the calendar icon and the inner
                     span with it, and the control rendered with no icon. --}}
                <div id="reportrange" class="admin-trx-control admin-trx-daterange__control">
                    <div class="admin-trx-daterange__label">
                        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                        <span class="text-nowrap flex-grow-1"></span>
                    </div>
                    <x-icon name="angle-down" class="text-muted flex-shrink-0"/>
                </div>

                <a href="{{ route('admin.transaction.export', request()->query()) }}"
                   class="admin-trx-control admin-trx-export">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"></path></svg>
                    {{ __('Export CSV') }}
                </a>
            </div>
        </x-slot:actions>
    </x-page-header>

    {{-- Each card is the filter that produces its own number, so the summary is
         also the way an operator narrows to it. --}}
    <div class="admin-trx-statcards">
        @foreach($headerStats as $stat)
            @php
                $statActive = (string) request('status', '') === $stat['key'];
                $statQuery  = array_filter(
                    array_merge(
                        request()->except(['status', 'page']),
                        $stat['key'] === '' ? [] : ['status' => $stat['key']]
                    ),
                    static fn ($value): bool => $value !== null && $value !== ''
                );
            @endphp
            <a href="{{ route('admin.transaction', $statQuery) }}"
               class="admin-trx-statcard {{ $statActive ? 'is-active' : '' }}"
               @if($statActive) aria-current="true" @endif>
                <span class="admin-trx-statcard__body">
                    {{-- The icon carries the tone now, so the dot that used to sit
                         here would be a second marker saying the same thing. --}}
                    <span class="admin-trx-statcard__head">{{ $stat['label'] }}</span>
                    {{-- The tone only reaches the number on the two cards that ask
                         for action, and a zero always recedes: an empty Failed card
                         should read as calm, not as a headline. --}}
                    <span class="admin-trx-statcard__value admin-trx-statcard__value--{{ $stat['tone'] }} {{ $stat['count'] === 0 ? 'is-zero' : '' }}">{{ $stat['value'] }}</span>
                    <span class="admin-trx-statcard__sub">{{ $stat['sub'] }}</span>
                </span>
                <span class="admin-trx-tile admin-trx-tile--{{ $stat['tone'] }} admin-trx-statcard__icon" aria-hidden="true">
                    @switch($stat['tone'])
                        @case('success')
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            @break
                        @case('warning')
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 15 14"></polyline></svg>
                            @break
                        @case('danger')
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"></path><path d="M12 9v4M12 17h.01"></path></svg>
                            @break
                        @default
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5z"></path><path d="M2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                    @endswitch
                </span>
                {{-- The mix at a glance. Decorative: the same proportion is in
                     the numbers themselves, so it is hidden from assistive tech. --}}
                <span class="admin-trx-statcard__meter" aria-hidden="true">
                    <span class="admin-trx-statcard__meter-fill admin-trx-statcard__meter-fill--{{ $stat['tone'] }}"
                          style="width: {{ $stat['share'] }}%"></span>
                </span>
            </a>
        @endforeach
    </div>

    {{-- `.admin-users-page` is what defines the --aul-* tokens the shell below
         reads. Without it the pills render transparent and the name/meta text
         loses its colour, because every one of those rules resolves a variable
         scoped to this class. --}}
    <div class="admin-users-page card border-0 mb-4">
        <div class="card-body">
            @include('backend.transaction.partials._filter', ['transactionsTotal' => $transactions->total()])

            {{-- Appears once a row is checked. It carries no bulk action because
                 no bulk route exists yet; it is the selection's own readout. --}}
            <div class="admin-trx-bulkbar" data-trx-bulkbar hidden>
                <span><strong data-trx-selected-count>0</strong> {{ __('selected') }}</span>
                <button type="button" class="admin-trx-bulkbar__clear" data-trx-clear-selection>{{ __('Clear') }}</button>
            </div>

            <section class="admin-users-panel">
                @include('backend.transaction.partials._table', [
                    'transactions' => $transactions,
                    'selectable'   => true,
                ])
            </section>

            @if($transactions->hasPages())
                <div class="d-flex justify-content-end mt-3">
                    {{ $transactions->links() }}
                </div>
            @endif
        </div>
    </div>

    @include('backend.transaction.partials._detail_modal')
@endsection

@push('scripts')
    <script>
    "use strict";

    /*
     * Selection is a client-side readout and picking a date range applies it.
     * Opening a row is not here — that is `backend/js/transaction-detail.js`,
     * which fetches the authoritative row rather than rebuilding it from
     * data-* attributes that only ever described what the list already showed.
     */
    (function () {
        var bulkbar = document.querySelector('[data-trx-bulkbar]');
        var counter = document.querySelector('[data-trx-selected-count]');

        function boxes() {
            return document.querySelectorAll('[data-trx-select]');
        }

        function syncSelection(source) {
            if (source && source.hasAttribute('data-trx-select-all')) {
                Array.prototype.forEach.call(boxes(), function (box) {
                    box.checked = source.checked;
                });
            }

            var selected = 0;

            Array.prototype.forEach.call(boxes(), function (box) {
                if (box.checked) {
                    selected += 1;
                }
            });

            if (counter) {
                counter.textContent = String(selected);
            }

            if (bulkbar) {
                bulkbar.hidden = selected === 0;
            }
        }

        document.addEventListener('change', function (event) {
            if (event.target.closest('[data-trx-select], [data-trx-select-all]')) {
                syncSelection(event.target);
            }
        });

        var clearButton = document.querySelector('[data-trx-clear-selection]');

        if (clearButton) {
            clearButton.addEventListener('click', function () {
                Array.prototype.forEach.call(boxes(), function (box) {
                    box.checked = false;
                });

                var all = document.querySelector('[data-trx-select-all]');

                if (all) {
                    all.checked = false;
                }

                syncSelection(null);
            });
        }

        /*
         * `general/_date_range_picker` writes the hidden input on apply but
         * never submits, so a picked range sat in the form doing nothing until
         * something else was applied. Read the range off the picker rather than
         * relying on which handler bound first, then submit the form the input
         * lives in.
         */
        if (window.jQuery) {
            window.jQuery('#reportrange').on('apply.daterangepicker', function (event, picker) {
                var input = document.querySelector('input[name="daterange"]');

                if (!input || !input.form) {
                    return;
                }

                input.value = picker.startDate.format('YYYY-MM-DD') + ',' + picker.endDate.format('YYYY-MM-DD');
                input.form.submit();
            });
        }

    }());
    </script>

    {{--
        Versioned like every other page-scoped asset. Without it a browser that
        already has the file keeps running that copy after a deploy, which is
        how a shipped change reads as one that never worked.
    --}}
    <script src="{{ asset('backend/js/transaction-detail.js').'?v='.config('app.version').'-'.filemtime(public_path('backend/js/transaction-detail.js')) }}"></script>
@endpush
