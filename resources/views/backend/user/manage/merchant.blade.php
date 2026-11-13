@extends('backend.user.manage')

@section('user_manage_content')
    <section class="um-card" data-um-merchants>
        <div class="um-card__head">
            <h2 class="um-card__title">{{ __('Merchant shops') }}</h2>
            <span class="um-card__aside">{{ __(':count shop(s)', ['count' => $user->merchants->count()]) }}</span>
        </div>

        @forelse($user->merchants as $merchant)
            <div class="um-list__row">
                <span class="um-list__copy">
                    <span class="um-list__title">{{ $merchant->business_name }}</span>
                    <span class="um-list__sub"><span class="um-mono" dir="ltr">{{ $merchant->merchant_key }}</span>@if($merchant->site_url) · {{ $merchant->site_url }}@endif · {{ $merchant->created_at->diffForHumans() }}</span>
                </span>
                <span class="um-list__end">
                    <span class="um-badge um-badge--{{ match ($merchant->status->color()) { 'success' => 'success', 'warning' => 'warning', 'danger' => 'danger', default => 'neutral' } }}">{{ $merchant->status->label() }}</span>
                    <button type="button" class="um-btn um-btn--sm" data-coreui-toggle="modal" data-coreui-target="#review-{{ $merchant->id }}">{{ __('Review') }}</button>
                </span>
                @include('backend.merchant.partials._review_modal')
            </div>
        @empty
            <div class="um-card__empty">{{ __('This user has not created any merchant profile yet.') }}</div>
        @endforelse
    </section>
@endsection
