@extends('frontend.layouts.user.index')
@section('title', __('Marketplace Orders'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/frontend/css/marketplace.css') }}?v={{ filemtime(public_path('assets/frontend/css/marketplace.css')) }}">
@endpush

@section('content')

    @if(session('mkt_payment_success'))
        @php($mktSuccess = session('mkt_payment_success'))
        <div class="mkt-success" role="status">
            <div class="mkt-success__icon"><i class="fa-solid fa-check"></i></div>
            <h5 class="mt-3 mb-0 fw-bold">{{ __('Payment sent') }}</h5>
            <div class="text-muted small">{{ __('to :vendor', ['vendor' => $mktSuccess['vendor_name']]) }}</div>
            <div class="mkt-success__amount">{{ money($mktSuccess['amount'], $mktSuccess['code'], false) }} {{ $mktSuccess['code'] }}</div>
            @if(!empty($mktSuccess['note']))
                <div class="text-muted small">“{{ $mktSuccess['note'] }}”</div>
            @endif
            <div class="mkt-success__meta">
                <div class="mkt-success__meta-row">
                    <span>{{ __('Order') }}</span><b>#{{ $mktSuccess['order_id'] ?? '' }}</b>
                </div>
                <div class="mkt-success__meta-row">
                    <span>{{ __('Business received') }}</span><span class="is-net">{{ money($mktSuccess['net'], $mktSuccess['code'], false) }} {{ $mktSuccess['code'] }}</span>
                </div>
                <div class="mkt-success__meta-row">
                    <span>{{ __('Paid from') }}</span><b>{{ $mktSuccess['wallet_code'] }} {{ __('wallet') }}</b>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4 justify-content-center">
                <a href="{{ route('user.marketplace.pay', $mktSuccess['vendor_id']) }}" class="btn btn-light-primary">
                    <i class="fa-solid fa-rotate-right me-1"></i> {{ __('Pay again') }}
                </a>
            </div>
        </div>
    @endif

    <div class="single-form-card">
        <x-user-feature-header
            :title="__('Marketplace Orders')"
            :subtitle="$vendor ? __('Your purchases and the sales received by :shop.', ['shop' => $vendor->display_name]) : __('Everything you have paid to marketplace vendors.')"
            icon="fas fa-receipt"
        >
            <a class="btn btn-light-primary btn-sm" href="{{ route('user.marketplace.index') }}">
                <i class="fa-solid fa-store"></i> {{ __('Browse Vendors') }}
            </a>
        </x-user-feature-header>

        <div class="card-main">
            <h6 class="mb-3"><i class="fa-solid fa-bag-shopping me-1"></i> {{ __('My Purchases') }}</h6>

            @if($purchases->count() > 0)
                <div class="history-table">
                    <div class="table-list">
                        <ul class="list-header">
                            <li>{{ __('Order') }}</li>
                            <li>{{ __('Vendor') }}</li>
                            <li>{{ __('Amount') }}</li>
                            <li>{{ __('Status') }}</li>
                            <li>{{ __('Date') }}</li>
                        </ul>
                    </div>
                    <div class="table-list">
                        @foreach($purchases as $order)
                            <ul class="list-content">
                                <li>#{{ $order->id }}</li>
                                <li>{{ $order->vendor?->display_name }}</li>
                                <li>{{ money($order->gross_amount, $order->currency?->code, false) }} {{ $order->currency?->code }}</li>
                                <li><span class="{{ $order->status->badgeClass() }} text-white">{{ $order->status->label() }}</span></li>
                                <li>{{ $order->created_at->format('M d, Y H:i') }}</li>
                            </ul>
                        @endforeach
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-3">
                    {{ $purchases->links() }}
                </div>
            @else
                <x-user-not-found
                    :title="__('No purchases yet')"
                    :message="__('When you pay a marketplace vendor, the order will show up here.')"
                    icon="fa-bag-shopping"
                    :action-url="route('user.marketplace.index')"
                    :action-label="__('Browse Vendors')"
                    action-icon="fa-store"
                />
            @endif

            @if($vendor)
                <hr class="my-4">
                <h6 class="mb-3"><i class="fa-solid fa-receipt me-1" aria-hidden="true"></i> {{ __('Sales Received (:shop)', ['shop' => $vendor->display_name]) }}</h6>

                @if($sales && $sales->count() > 0)
                    <div class="history-table">
                        <div class="table-list">
                            <ul class="list-header">
                                <li>{{ __('Order') }}</li>
                                <li>{{ __('Buyer') }}</li>
                                <li>{{ __('Gross') }}</li>
                                <li>{{ __('You Received') }}</li>
                                <li>{{ __('Status') }}</li>
                                <li>{{ __('Date') }}</li>
                            </ul>
                        </div>
                        <div class="table-list">
                            @foreach($sales as $order)
                                <ul class="list-content">
                                    <li>#{{ $order->id }}</li>
                                    <li>{{ $order->buyer?->name }}</li>
                                    <li>{{ money($order->gross_amount, $order->currency?->code, false) }} {{ $order->currency?->code }}</li>
                                    <li class="text-success">+{{ money($order->vendor_net, $order->currency?->code, false) }}</li>
                                    <li><span class="{{ $order->status->badgeClass() }} text-white">{{ $order->status->label() }}</span></li>
                                    <li>{{ $order->created_at->format('M d, Y H:i') }}</li>
                                </ul>
                            @endforeach
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-3">
                        {{ $sales->links() }}
                    </div>
                @else
                    <x-user-not-found
                        :title="__('No sales yet')"
                        :message="__('Payments from your customers will appear here with the net amount you receive.')"
                        icon="fa-receipt"
                    />
                @endif
            @endif
        </div>
    </div>

@endsection
