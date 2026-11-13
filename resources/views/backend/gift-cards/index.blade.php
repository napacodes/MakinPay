@extends('backend.layouts.app')
@section('title', __('Gift Cards'))

@section('content')
    @php
        /*
         * Status registry — drives both the filter <select> and each
         * row's pill. Each entry pairs a label with the design token
         * suffix used by .gc-admin-pill (success / warning / info /
         * primary / secondary / danger) so the row colour stays in
         * sync with the meaning of the status.
         */
        $statusMap = [
            'pending'   => ['label' => __('Pending'),   'cls' => 'warning'],
            'scheduled' => ['label' => __('Scheduled'), 'cls' => 'info'],
            'delivered' => ['label' => __('Delivered'), 'cls' => 'success'],
            'redeemed'  => ['label' => __('Redeemed'),  'cls' => 'primary'],
            'expired'   => ['label' => __('Expired'),   'cls' => 'secondary'],
            'cancelled' => ['label' => __('Cancelled'), 'cls' => 'danger'],
        ];
    @endphp

    <div class="gift-card-admin">

        {{-- The four KPI cards are the header's strip now. --}}
        <x-page-header
            :title="__('All Gift Cards')"
            :subtitle="__('Every card issued, delivered, redeemed, or cancelled across the platform.')"
            {{--
                `gift` is a 65-shape unDraw illustration on a 671×516 canvas,
                not an icon: at 24px inside the tile it collapsed into a dark
                smudge. `voucher` is a 24×24 currentColor stroke icon, so it
                takes the tile's white and the stat tile's tone.
            --}}
            icon="voucher"
            :stats="[
                ['label' => __('Total'), 'value' => (int) $stats['total'], 'icon' => 'voucher'],
                ['label' => __('Delivered'), 'value' => (int) $stats['delivered'], 'tone' => 'success', 'icon' => 'check'],
                ['label' => __('Redeemed'), 'value' => (int) $stats['redeemed'], 'tone' => 'brand', 'icon' => 'wallet-payment'],
                ['label' => __('Total value'), 'value' => money($stats['value']), 'icon' => 'money'],
            ]"
        >
            @can('gift-card-template-list')
                <x-slot:actions>
                    <a href="{{ route('admin.gift-card-templates.index') }}" class="ph-btn ph-btn--primary">
                        <x-icon name="apps" width="14" height="14"/>
                        {{ __('Manage Templates') }}
                    </a>
                </x-slot:actions>
            @endcan
        </x-page-header>

        {{-- Filter + table --}}
        <div class="card border-0 mb-4 gift-card-admin__card">
            <div class="card-body">
                <form method="GET" class="gift-card-admin__filter mb-3">
                    <div class="gift-card-admin__filter-search">
                        <i class="fa-solid fa-magnifying-glass gift-card-admin__filter-search-icon" aria-hidden="true"></i>
                        <input type="text"
                               name="q"
                               value="{{ request('q') }}"
                               class="form-control gift-card-admin__filter-input"
                               placeholder="{{ __('Search by code, name, or email') }}">
                    </div>
                    <select name="status" class="form-select gift-card-admin__filter-select">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach($statusMap as $key => $meta)
                            <option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>{{ $meta['label'] }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-primary gift-card-admin__filter-submit" type="submit">
                        <x-icon name="search" width="16" height="16"/>
                        <span>{{ __('Filter') }}</span>
                    </button>
                </form>

                <div class="table-responsive">
                    <table class="table align-middle mb-0 gc-admin-table">
                        <thead>
                            <tr>
                                <th>{{ __('Code') }}</th>
                                <th>{{ __('Sender') }}</th>
                                <th>{{ __('Recipient') }}</th>
                                <th>{{ __('Amount') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Created') }}</th>
                                <th class="text-end">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($giftCards as $card)
                                @php $meta = $statusMap[$card->status] ?? ['label' => ucfirst($card->status), 'cls' => 'secondary']; @endphp
                                <tr>
                                    <td>
                                        <span class="gc-admin-code" title="{{ $card->code }}">
                                            <i class="fa-solid fa-hashtag gc-admin-code__hash" aria-hidden="true"></i>
                                            {{ $card->code }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="gc-admin-person">
                                            <span class="gc-admin-person__name">{{ $card->sender_name }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="gc-admin-person">
                                            <span class="gc-admin-person__name">{{ $card->recipient_name }}</span>
                                            <span class="gc-admin-person__meta">{{ $card->recipient_email }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="gc-admin-amount">
                                            <span class="gc-admin-amount__sym">{{ $card->currency?->symbol ?? siteCurrency('symbol') }}</span>{{ money($card->amount, $card->currency?->code, false) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="gc-admin-pill gc-admin-pill--{{ $meta['cls'] }}">
                                            <span class="gc-admin-pill__dot" aria-hidden="true"></span>
                                            {{ $meta['label'] }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="gc-admin-date">
                                            <span class="gc-admin-date__main">{{ $card->created_at->format('M d, Y') }}</span>
                                            <span class="gc-admin-date__meta">{{ $card->created_at->diffForHumans() }}</span>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="admin-table-actions">
                                            <a href="{{ route('gift-card.preview', $card->code) }}"
                                               target="_blank"
                                               class="btn btn-outline-primary btn-sm admin-table-action"
                                               title="{{ __('Open preview in a new tab') }}">
                                                <x-icon name="arrow-up-right" width="14" height="14"/>
                                                <span>{{ __('View') }}</span>
                                            </a>

                                            @can('gift-card-manage')
                                                @if(in_array($card->status, ['pending', 'scheduled', 'delivered'], true))
                                                    <form action="{{ route('admin.gift-cards.cancel', $card->id) }}"
                                                          method="POST"
                                                          class="admin-table-actions__form">
                                                        @csrf
                                                        <button type="submit"
                                                                class="btn btn-outline-danger btn-sm admin-table-action admin-table-action--danger"
                                                                onclick="return confirm('{{ __('Cancel this gift card and refund its value to the sender?') }}')"
                                                                title="{{ __('Cancel this gift card') }}">
                                                            <x-icon name="x-circle" width="14" height="14"/>
                                                            <span>{{ __('Cancel') }}</span>
                                                        </button>
                                                    </form>
                                                @endif
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <x-admin-not-found
                                            :title="__('No gift cards yet')"
                                            :message="__('Once users start sending gift cards, every transaction will appear here.')"
                                            icon="fa-gift"
                                        />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($giftCards->hasPages())
                    <div class="mt-3">
                        {{ $giftCards->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    @push('styles')
        <style>
            /* ──────────────────────────────────────────────────────────
               Gift Card admin — premium polish shared across All Gift
               Cards + Template Manager. Scoped to .gift-card-admin so
               the global admin layout is untouched.
               ────────────────────────────────────────────────────────── */

            /* Card wrapper around the table */
            .gift-card-admin__card {
                border-radius: 14px;
                box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 1px 1px rgba(15, 23, 42, .03);
            }
            .gift-card-admin__card > .card-body {
                padding: 18px 20px 20px;
            }

            /* Filter bar — search input with leading icon, status select,
               submit button. Sits on the same baseline. */
            .gift-card-admin__filter {
                display: grid;
                grid-template-columns: 1fr auto auto;
                gap: 10px;
                align-items: stretch;
            }
            @media (max-width: 575.98px) {
                .gift-card-admin__filter { grid-template-columns: 1fr; }
            }
            .gift-card-admin__filter-search { position: relative; }
            .gift-card-admin__filter-search-icon {
                position: absolute;
                left: 14px;
                top: 50%;
                transform: translateY(-50%);
                color: #94A3B8;
                font-size: 13px;
                pointer-events: none;
            }
            .gift-card-admin__filter-input {
                height: 40px;
                padding-left: 38px;
                border-radius: 10px;
                border-color: #E6EAF3;
                font-size: 0.85rem;
                box-shadow: none;
            }
            .gift-card-admin__filter-input:focus {
                border-color: #93C5FD;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.16);
            }
            .gift-card-admin__filter-select {
                height: 40px;
                min-width: 160px;
                border-radius: 10px;
                border-color: #E6EAF3;
                font-size: 0.85rem;
                font-weight: 600;
                color: #1E293B;
                box-shadow: none;
            }
            .gift-card-admin__filter-select:focus {
                border-color: #93C5FD;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.16);
            }
            .gift-card-admin__filter-submit {
                height: 40px;
                padding: 0 18px;
                border-radius: 10px;
                font-size: 0.825rem;
                font-weight: 700;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }

            /* ─── Table ────────────────────────────────────────────────── */
            .gc-admin-table { margin: 0; }
            .gc-admin-table thead th {
                background: #F8FAFC;
                color: #64748B;
                font-size: 10.5px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                padding: 12px 14px;
                border-bottom: 1px solid #E6EAF3;
                border-top: 0;
                white-space: nowrap;
            }
            .gc-admin-table thead th:first-child { border-top-left-radius: 10px; }
            .gc-admin-table thead th:last-child  { border-top-right-radius: 10px; }
            .gc-admin-table tbody td {
                padding: 14px;
                vertical-align: middle;
                border-top: 1px solid #EEF1F7;
                font-size: 0.85rem;
                color: #0F172A;
            }
            .gc-admin-table tbody tr {
                transition: background-color 0.12s ease;
            }
            .gc-admin-table tbody tr:hover td {
                background-color: #FAFBFE;
            }

            /* Code chip */
            .gc-admin-code {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 5px 10px 5px 8px;
                border-radius: 7px;
                background: #F1F5F9;
                color: #1E293B;
                font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
                font-size: 0.74rem;
                font-weight: 700;
                letter-spacing: 0.02em;
                border: 1px solid #E2E8F0;
            }
            .gc-admin-code__hash { color: #94A3B8; font-size: 0.7rem; }

            /* Person / recipient cell — name on top, meta dimmed below */
            .gc-admin-person { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
            .gc-admin-person__name {
                font-weight: 600;
                color: #0F172A;
                font-size: 0.85rem;
                line-height: 1.25;
            }
            .gc-admin-person__meta {
                color: #94A3B8;
                font-size: 0.72rem;
                font-weight: 500;
                line-height: 1.2;
            }

            /* Amount cell — bold tabular numerals with softer symbol */
            .gc-admin-amount {
                font-weight: 800;
                font-size: 0.92rem;
                color: #0F172A;
                font-variant-numeric: tabular-nums;
                letter-spacing: -0.01em;
            }
            .gc-admin-amount__sym { color: #94A3B8; font-weight: 700; margin-right: 1px; }

            /* Date cell — two-line stack */
            .gc-admin-date { display: flex; flex-direction: column; gap: 2px; line-height: 1.2; }
            .gc-admin-date__main { color: #0F172A; font-weight: 600; font-size: 0.81rem; }
            .gc-admin-date__meta { color: #94A3B8; font-size: 0.7rem; font-weight: 500; }

            /* Status pill — soft pastel background, dot indicator, and a
               coloured ring so the chip looks like a proper status token
               rather than a flat Bootstrap badge. */
            .gc-admin-pill {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 10px 4px 8px;
                border-radius: 999px;
                font-size: 0.72rem;
                font-weight: 700;
                line-height: 1;
                letter-spacing: 0.01em;
                white-space: nowrap;
            }
            .gc-admin-pill__dot {
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: currentColor;
                box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.6);
                flex-shrink: 0;
            }
            .gc-admin-pill--success  { background: #DCFCE7; color: #15803D; }
            .gc-admin-pill--warning  { background: #FEF3C7; color: #92400E; }
            .gc-admin-pill--danger   { background: #FEE2E2; color: #B91C1C; }
            .gc-admin-pill--info     { background: #DBEAFE; color: #1D4ED8; }
            .gc-admin-pill--primary  { background: #EDE9FE; color: #6D28D9; }
            .gc-admin-pill--secondary{ background: #E2E8F0; color: #475569; }

        </style>
    @endpush
@endsection
