@extends('frontend.layouts.user.index')
@section('title', __('Become a Vendor'))
@section('content')

    <div class="single-form-card">
        <x-user-feature-header
            :title="__('Become a Vendor')"
            :subtitle="__('Receive payments from customers directly into your wallet — the platform keeps only a small commission.')"
            icon="fas fa-store"
        >
            <a class="btn btn-light-primary btn-sm" href="{{ route('user.marketplace.index') }}">
                <i class="fa-solid fa-arrow-left"></i> {{ __('All Vendors') }}
            </a>
        </x-user-feature-header>

        <div class="card-main">
            @if($vendor)
                <div class="text-center py-4">
                    <span class="{{ $vendor->status->badgeClass() }} text-white fs-6 px-3 py-2">
                        {{ $vendor->status->label() }}
                    </span>
                    <h5 class="mt-3">{{ $vendor->display_name }}</h5>

                    @if($vendor->status === \App\Enums\Marketplace\VendorStatus::PENDING)
                        <p class="text-muted mb-0">{{ __('Your application is under review. You will be able to receive payments once an administrator approves it.') }}</p>
                    @elseif($vendor->status === \App\Enums\Marketplace\VendorStatus::ACTIVE)
                        <p class="text-muted">{{ __('Your shop is live. Customers can pay you from the vendor list.') }}</p>
                        <a href="{{ route('user.marketplace.orders') }}" class="btn btn-light-success btn-sm">
                            <i class="fa-solid fa-receipt" aria-hidden="true"></i> {{ __('View My Sales') }}
                        </a>

                        @if($connectEnabled)
                            <div class="mt-4 pt-4 border-top text-start">
                                <h6 class="mb-1">{{ __('Card payments via Stripe') }}</h6>
                                <p class="text-muted small mb-3">
                                    {{ __('Connect a Stripe account to accept card payments. Your share is paid straight into your own Stripe balance.') }}
                                </p>

                                @if($connectReady)
                                    <span class="badge bg-success text-white mb-2">{{ __('Stripe connected') }}</span>
                                    <div>
                                        <a href="{{ route('user.marketplace.stripe.dashboard') }}" class="btn btn-light-primary btn-sm">
                                            <i class="fa-brands fa-stripe-s" aria-hidden="true"></i> {{ __('Open Stripe Dashboard') }}
                                        </a>
                                    </div>
                                @else
                                    <a href="{{ route('user.marketplace.stripe.onboard') }}" class="btn btn-light-primary btn-sm">
                                        <i class="fa-brands fa-stripe-s" aria-hidden="true"></i> {{ __('Connect with Stripe') }}
                                    </a>
                                @endif
                            </div>
                        @endif
                    @else
                        <p class="text-muted mb-0">{{ __('Your vendor account is suspended. Please contact support for assistance.') }}</p>
                    @endif
                </div>
            @else
                <form action="{{ route('user.marketplace.apply.store') }}" method="POST">
                    @csrf

                    <div class="row g-3">
                        <div class="col-12">
                            <label for="display_name" class="form-label">{{ __('Shop / business name') }} <span class="text-danger">*</span></label>
                            <input type="text" name="display_name" id="display_name" minlength="3" maxlength="100"
                                   class="form-control @error('display_name') is-invalid @enderror"
                                   value="{{ old('display_name') }}" placeholder="{{ __('e.g. Rahim Gadgets') }}" required>
                            @error('display_name')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label for="category" class="form-label">{{ __('Business category') }} <span class="text-danger">*</span></label>
                            <select name="category" id="category" class="form-select @error('category') is-invalid @enderror" required>
                                <option value="" disabled @selected(!old('category'))>{{ __('Select what you offer...') }}</option>
                                @foreach(\App\Models\Vendor::CATEGORIES as $catKey => $catLabel)
                                    <option value="{{ $catKey }}" @selected(old('category') === $catKey)>{{ __($catLabel) }}</option>
                                @endforeach
                            </select>
                            @error('category')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label for="about" class="form-label">{{ __('What do you sell? (optional)') }}</label>
                            <textarea name="about" id="about" rows="3" maxlength="1000"
                                      class="form-control @error('about') is-invalid @enderror"
                                      placeholder="{{ __('Short description shown to buyers') }}">{{ old('about') }}</textarea>
                            @error('about')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-base w-100">
                                <i class="fa-solid fa-paper-plane me-1"></i> {{ __('Submit Application') }}
                            </button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>

@endsection
