@extends('backend.user.manage')

@section('user_manage_content')
    @php
        $badgeFor = function ($referred): array {
            if ($referred === null) {
                return ['label' => __('Unknown'), 'tone' => 'neutral'];
            }

            return match (true) {
                $referred->status !== \App\Enums\UserStatus::ACTIVE => ['label' => __('Inactive'), 'tone' => 'danger'],
                ! $referred->isKycVerified()                        => ['label' => __('Pending KYC'), 'tone' => 'warning'],
                default                                             => ['label' => __('Active'), 'tone' => 'success'],
            };
        };
    @endphp

    <section class="um-card" data-um-referrals>
        <div class="um-card__head">
            <h2 class="um-card__title">{{ __('Referral network') }}</h2>
            <span class="um-card__aside">{{ __('Level 1 and 2 members') }}</span>
        </div>

        @forelse($referrals as $referral)
            @php($badge = $badgeFor($referral->referredUser))
            <a href="{{ $referral->referredUser ? route('admin.user.manage', $referral->referredUser->username) : '#' }}" class="um-list__row">
                <span class="um-list__copy">
                    <span class="um-list__title">{{ $referral->referredUser?->name ?? __('Unknown') }}</span>
                    <span class="um-list__sub">{{ __('Level 1') }} · {{ '@'.($referral->referredUser?->username ?? '—') }}</span>
                </span>
                <span class="um-list__end">
                    <span class="um-badge um-badge--{{ $badge['tone'] }}">{{ $badge['label'] }}</span>
                    <span class="um-list__when">{{ $referral->created_at?->format('M Y') }}</span>
                </span>
            </a>
            @foreach($referral->childReferrals as $child)
                @php($childBadge = $badgeFor($child->referredUser))
                <a href="{{ $child->referredUser ? route('admin.user.manage', $child->referredUser->username) : '#' }}" class="um-list__row">
                    <span class="um-list__copy">
                        <span class="um-list__title">{{ $child->referredUser?->name ?? __('Unknown') }}</span>
                        <span class="um-list__sub">{{ __('Level 2 · via :name', ['name' => $referral->referredUser?->name ?? __('Unknown')]) }}</span>
                    </span>
                    <span class="um-list__end">
                        <span class="um-badge um-badge--{{ $childBadge['tone'] }}">{{ $childBadge['label'] }}</span>
                        <span class="um-list__when">{{ $child->created_at?->format('M Y') }}</span>
                    </span>
                </a>
            @endforeach
        @empty
            <div class="um-card__empty">{{ __('No one has joined through this user yet.') }}</div>
        @endforelse
    </section>
@endsection
