@extends('frontend.layouts.user.index')
@section('title', __('Bill payment history'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/bill-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/bill-payment.css'))) }}">
@endpush

@section('content')
    @php
        $bpFiltered = $status !== null || $openOnly || $category !== '';
    @endphp

    <div class="bp">
        <div class="single-form-card">
            <x-user-feature-header
                :title="__('Bill payment history')"
                :subtitle="__('Every bill you have paid, with its receipt.')"
                icon="fas fa-clock-rotate-left"
            >
                <a class="btn btn-light-success btn-sm" href="{{ route('user.bill-payment.index') }}">
                    <i class="fas fa-receipt" aria-hidden="true"></i> {{ __('Pay a bill') }}
                </a>
            </x-user-feature-header>

            <div class="card-main">
                <form method="GET" class="bp-filters" role="search">
                    <div class="bp-filters__field">
                        <label class="bp-filters__label" for="bp-filter-status">{{ __('Status') }}</label>
                        <select id="bp-filter-status" name="status" class="form-select" data-bp-autosubmit>
                            <option value="">{{ __('All statuses') }}</option>
                            <option value="{{ \App\Http\Controllers\Frontend\BillPaymentController::OPEN_FILTER }}" @selected($openOnly)>{{ __('Not settled yet') }}</option>
                            @foreach($statuses as $option)
                                <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->customerLabel() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="bp-filters__field">
                        <label class="bp-filters__label" for="bp-filter-category">{{ __('Category') }}</label>
                        <select id="bp-filter-category" name="category" class="form-select" data-bp-autosubmit>
                            <option value="">{{ __('All categories') }}</option>
                            @foreach($categories as $option)
                                <option value="{{ $option->code }}" @selected($category === $option->code)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <noscript><button type="submit" class="btn btn-light btn-sm">{{ __('Filter') }}</button></noscript>
                    @if($bpFiltered)
                        <a class="bp-filters__clear" href="{{ route('user.bill-payment.history') }}">{{ __('Clear filters') }}</a>
                    @endif
                </form>

                @if($payments->isEmpty())
                    @if($bpFiltered)
                        <x-user-not-found
                            :title="__('No payments match your filter')"
                            :message="__('Try another status or category, or clear the filter.')"
                            icon="fa-receipt"
                            :action-url="route('user.bill-payment.history')"
                            :action-label="__('Clear filters')"
                            action-icon="fa-rotate"
                        />
                    @else
                        <x-user-not-found
                            :title="__('No bills paid yet')"
                            :message="__('Your bill payments and their receipts will appear here.')"
                            icon="fa-receipt"
                            :action-url="route('user.bill-payment.index')"
                            :action-label="__('Pay a bill')"
                            action-icon="fa-receipt"
                        />
                    @endif
                @else
                    <div class="bp-payments">
                        @foreach($payments as $payment)
                            @include('frontend.user.bill_payment.partials._payment_row')
                        @endforeach
                    </div>

                    @if($payments->hasPages())
                        <div class="bp-pagination">
                            {{ $payments->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('frontend/js/bill-payment.js?v='.config('app.version').'-'.filemtime(public_path('frontend/js/bill-payment.js'))) }}"></script>
@endpush
