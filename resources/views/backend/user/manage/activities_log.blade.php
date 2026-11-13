@extends('backend.user.manage')

@section('user_manage_content')
    <section class="um-card" data-um-activity>
        <div class="um-card__head">
            <div>
                <h2 class="um-card__title">{{ __('Activity log') }}</h2>
                <p class="um-card__sub">{{ __('Sign-ins by device, browser and location') }}</p>
            </div>
            <form method="GET" action="{{ route('admin.user.manage', ['username' => $user->username, 'param' => 'activities']) }}" class="um-toolbar__end">
                <input type="hidden" name="daterange" value="{{ request('daterange') }}" data-um-daterange-value>
                <button type="button" class="um-btn um-btn--sm" data-um-daterange data-i18n-all="{{ __('All time') }}">{{ request('daterange') ?: __('All time') }}</button>
                <label class="um-search">
                    <span class="um-search__icon"><x-icon name="search"/></span>
                    <input type="search" name="search" class="um-input" value="{{ request('search') }}" placeholder="{{ __('Search IP, browser, country') }}">
                </label>
            </form>
        </div>

        @forelse($activities as $activity)
            <div class="um-list__row">
                <span class="um-list__copy">
                    <span class="um-list__title">{{ __('Signed in') }}</span>
                    <span class="um-list__sub">{{ implode(' · ', array_filter([$activity->browser ? __(':browser on :platform', ['browser' => $activity->browser, 'platform' => $activity->platform ?: __('unknown')]) : null, $activity->country, $activity->ip_address])) }}</span>
                </span>
                <span class="um-list__end">
                    <span class="um-list__when" title="{{ $activity->login_at?->format('Y-m-d H:i') }}">{{ $activity->login_at?->diffForHumans() }}</span>
                </span>
            </div>
        @empty
            <div class="um-card__empty">{{ __('This user has no activity logs matching the current filters.') }}</div>
        @endforelse

        @if($activities->hasPages())
            <div class="um-pager">
                <span>{{ __('Showing :from–:to of :total', ['from' => $activities->firstItem(), 'to' => $activities->lastItem(), 'total' => $activities->total()]) }}</span>
                <div class="um-pager__nav">
                    <a class="um-btn um-btn--sm" href="{{ $activities->previousPageUrl() ?? '#' }}" @if($activities->onFirstPage()) aria-disabled="true" @endif>{{ __('Previous') }}</a>
                    <a class="um-btn um-btn--sm" href="{{ $activities->nextPageUrl() ?? '#' }}" @unless($activities->hasMorePages()) aria-disabled="true" @endunless>{{ __('Next') }}</a>
                </div>
            </div>
        @endif
    </section>
@endsection
