@extends('frontend.layouts.user.index')
@section('title', $category->name)

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/bill-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/bill-payment.css'))) }}">
@endpush

@section('content')
    @php
        $bpCountryName = collect($countries)->firstWhere('code', $country)['name'] ?? $country;
    @endphp

    <div class="bp">
        <div class="single-form-card">
            <x-user-feature-header
                :title="$category->name"
                :subtitle="$country ? __('Choose who you are paying in :country.', ['country' => $bpCountryName]) : __('Choose who you are paying.')"
                icon="fas fa-receipt"
            >
                <a class="btn btn-light-success btn-sm" href="{{ route('user.bill-payment.index', array_filter(['country' => $country])) }}">
                    <i class="fa-solid fa-arrow-right bp-arrow bp-arrow--back" aria-hidden="true"></i> {{ __('All categories') }}
                </a>
            </x-user-feature-header>

            <div class="card-main">
                <div class="bp-toolbar">
                    <h2 class="bp-section-title">
                        {{ trans_choice(':count biller|:count billers', $billers->count(), ['count' => $billers->count()]) }}
                    </h2>
                    @include('frontend.user.bill_payment.partials._country_switch')
                </div>

                @if($billers->isEmpty())
                    <x-user-not-found
                        :title="__('No billers here yet')"
                        :message="__('There is no biller in this category for the selected country yet.')"
                        icon="fa-receipt"
                        :action-url="route('user.bill-payment.index', array_filter(['country' => $country]))"
                        :action-label="__('All categories')"
                        action-icon="fa-receipt"
                    />
                @else
                    <div class="bp-billers">
                        @foreach($billers as $biller)
                            <a class="bp-biller" href="{{ route('user.bill-payment.create', $biller->id) }}" data-bp-biller="{{ $biller->id }}">
                                @include('frontend.user.bill_payment.partials._logo', [
                                    'logo' => $biller->logo,
                                    'icon' => $category->icon,
                                    'size' => 'md',
                                ])
                                <span class="bp-biller__body">
                                    <span class="bp-biller__name">{{ $biller->name }}</span>
                                    <span class="bp-biller__hint">{{ $amountHints[$biller->id] ?? '' }}</span>
                                </span>
                                <i class="fa-solid fa-chevron-right bp-arrow" aria-hidden="true"></i>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('frontend/js/bill-payment.js?v='.config('app.version').'-'.filemtime(public_path('frontend/js/bill-payment.js'))) }}"></script>
@endpush
