@extends('frontend.layouts.user.index')
@section('title', __('Transfer History'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/remittance.css?v=' . filemtime(public_path('frontend/css/remittance.css'))) }}">
@endpush

@section('content')
    @php
        use App\Enums\RemittanceStatus;

        $filters = [
            ''           => __('All'),
            'pending'    => __('Pending'),
            'submitted'  => __('Submitted'),
            'processing' => __('Processing'),
            'completed'  => __('Completed'),
            'failed'     => __('Failed'),
            'refunded'   => __('Refunded'),
            'canceled'   => __('Canceled'),
        ];

        $statusToPill = [
            RemittanceStatus::Completed->value  => ['cls' => 'success', 'icon' => 'circle-check'],
            RemittanceStatus::Submitted->value  => ['cls' => 'info',    'icon' => 'paper-plane'],
            RemittanceStatus::Processing->value => ['cls' => 'warning', 'icon' => 'clock'],
            RemittanceStatus::Pending->value    => ['cls' => 'info',    'icon' => 'hourglass-half'],
            RemittanceStatus::Failed->value     => ['cls' => 'danger',  'icon' => 'circle-exclamation'],
            RemittanceStatus::Refunded->value   => ['cls' => 'info',    'icon' => 'rotate-left'],
            RemittanceStatus::Canceled->value   => ['cls' => 'muted',   'icon' => 'xmark'],
        ];

        $tones = ['indigo','rose','amber','teal','violet','sky'];
    @endphp

    <div class="row">
        <div class="col-12">
            <div class="single-form-card">
                @include('frontend.user.remittance.partials._page_header', [
                    'rmtPageTitle'    => __('Transfer History'),
                    'rmtPageSubtitle' => __('Track every remittance you have sent abroad.'),
                    'rmtPageIcon'     => 'fas fa-clock-rotate-left',
                    'rmtCurrentPage'  => 'index',
                ])
            </div>
        </div>
    </div>

    <div class="dk-rmt">

        {{-- Filter + Search --}}
        <form method="GET" class="dk-rmt__card dk-rmt__filter-card mb-3" role="search">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-7">
                    <label class="visually-hidden" for="remittance-search">{{ __('Search transfers') }}</label>
                    <div class="dk-rmt__search-wrap">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input type="search" name="q" id="remittance-search" class="dk-rmt__input"
                               value="{{ $search }}"
                               maxlength="120"
                               placeholder="{{ __('Search by reference, name or country...') }}">
                    </div>
                </div>
                <div class="col-12 col-md-5">
                    <label class="visually-hidden" for="remittance-status-filter">{{ __('Filter transfers by status') }}</label>
                    <select name="status" id="remittance-status-filter" class="form-select"
                            onchange="this.form.submit()" aria-label="{{ __('Filter transfers by status') }}">
                        @foreach ($filters as $key => $label)
                            <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </form>

        {{-- Transfer list --}}
        @if ($transfers->isEmpty())
            @if ($search || $status)
                <x-user-not-found
                    :title="__('No transfers match your filter')"
                    :message="__('Try a different search term or clear the filter.')"
                    icon="fa-paper-plane"
                    :action-url="route('user.remittance.index')"
                    :action-label="__('Clear filters')"
                    action-icon="fa-rotate-left"
                />
            @else
                <x-user-not-found
                    :title="__('No transfers yet')"
                    :message="__('Your future international transfers will show up here.')"
                    icon="fa-paper-plane"
                    :action-url="route('user.remittance.create')"
                    :action-label="__('Send your first transfer')"
                    action-icon="fa-paper-plane"
                />
            @endif
        @else
            <div class="dk-rmt__history">
                @foreach ($transfers as $transfer)
                    @php
                        $pill = $statusToPill[$transfer->status->value] ?? ['cls' => 'muted', 'icon' => 'circle'];
                        $tone = $tones[($transfer->beneficiary?->id ?? 0) % count($tones)];
                        $initials = collect(explode(' ', $transfer->recipient_name))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
                    @endphp
                    <a class="dk-rmt__history-row" href="{{ route('user.remittance.show', $transfer->reference) }}">
                        <div class="dk-rmt__history-avatar">
                            <span class="dk-rmt__avatar dk-rmt__avatar--{{ $tone }}">{{ $initials }}</span>
                            <span class="dk-rmt__recipient-flag">{{ getCountryFlagEmoji($transfer->recipient_country) }}</span>
                        </div>
                        <div class="dk-rmt__history-body">
                            <div class="dk-rmt__history-name">{{ $transfer->recipient_name }}</div>
                            <div class="dk-rmt__history-amounts">
                                {{ __('Sent') }} <strong>{{ money($transfer->total_paid, $transfer->source_currency, false) }} {{ $transfer->source_currency }}</strong>
                                · {{ __('Recipient') }} <strong>{{ money($transfer->receive_amount, $transfer->destination_currency, false) }} {{ $transfer->destination_currency }}</strong>
                            </div>
                        </div>
                        <div class="dk-rmt__history-meta">
                            <span class="dk-rmt__pill dk-rmt__pill--{{ $pill['cls'] }}">
                                <i class="fa-solid fa-{{ $pill['icon'] }}" aria-hidden="true"></i>
                                {{ $transfer->status->label() }}
                            </span>
                            <span class="dk-rmt__history-time" title="{{ $transfer->created_at->format('M d, Y h:i A') }}">
                                {{ \Carbon\Carbon::parse($transfer->created_at)->diffForHumans(null, true, true) }}
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($transfers->hasPages())
                <div class="mt-3 d-flex justify-content-center">
                    {{ $transfers->links() }}
                </div>
            @endif
        @endif
    </div>
@endsection
