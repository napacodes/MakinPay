@extends('backend.layouts.app')

@section('title', __('IP deny list'))

@push('styles')
    @php
        $ipaCssVersion = config('app.version') . '-' . filemtime(public_path('backend/css/ip-access.css'));
    @endphp
    <link rel="stylesheet" href="{{ asset('backend/css/ip-access.css?v=' . $ipaCssVersion) }}">
@endpush

@section('content')
    @php
        /*
         * Every figure in the strip is a fact the operator acts on, so each
         * carries the tone of the thing it counts: rose for refusing now,
         * amber for the guard's own doing, indigo for still counting, green
         * for something about to resolve itself.
         */
        $headerStats = [
            [
                'label' => __('Refusing now'),
                'value' => $stats['active'],
                'tone'  => 'danger',
                'icon'  => 'shield-ban',
                'note'  => trans_choice('{0} Nothing permanent|{1} :count permanent|[2,*] :count permanent', $stats['permanent'], ['count' => $stats['permanent']])
                    . ' · '
                    . trans_choice('{0} none lapsing|{1} :count lapsing|[2,*] :count lapsing', $stats['lapsing'], ['count' => $stats['lapsing']]),
            ],
            [
                'label' => __('Added by the guard'),
                'value' => $stats['auto_day'],
                'tone'  => 'warning',
                'icon'  => 'barrier-block',
                'note'  => __('In the last 24 hours'),
            ],
            [
                'label' => __('Under watch'),
                'value' => $stats['watching'],
                'tone'  => 'primary',
                'icon'  => 'eye',
                'note'  => $stats['watching'] > 0
                    ? __('Highest so far :count of :threshold', ['count' => $stats['highest'], 'threshold' => $threshold])
                    : __('Nothing collecting strikes'),
            ],
            [
                /*
                 * The figure is the number alone and the unit lives in the
                 * note. `ph__value` renders as plain text, so it cannot carry
                 * a <bdi>, and "47 m" measured in Arabic came out as "m 47" —
                 * digits followed by a lone Latin letter is exactly the run
                 * the bidi algorithm reorders.
                 */
                'label' => __('Next to lapse'),
                'value' => $stats['next_minutes'] ?? '—',
                'tone'  => 'success',
                'icon'  => 'clock',
                'note'  => $stats['next_minutes'] === null
                    ? __('Nothing lapsing on its own')
                    : trans_choice('{1} minute|[2,*] minutes', $stats['next_minutes']).' · '.$stats['next_ip'],
            ],
        ];
    @endphp

    <div class="ipa-page">
        <x-page-header
            :title="__('IP deny list')"
            icon="lock"
            tile="brand"
            :eyebrow="__('Security')"
            :subtitle="__('Addresses refused before they reach a sign-in form. One that keeps being turned away is added here on its own and lapses on its own — the block is on the address, never on an account.')"
            :back="route('admin.activity-log')"
            :back-label="__('Back to Activity history')"
            :breadcrumbs="[['label' => __('Platform')], ['label' => __('Security')], ['label' => __('Activity history'), 'url' => route('admin.activity-log')], ['label' => __('IP deny list')]]"
            :status="$demoMode
                ? ['tone' => 'warning', 'label' => __('Paused in demo mode')]
                : ['tone' => $stats['active'] > 0 ? 'danger' : 'success', 'label' => trans_choice('{0} Nothing blocked|{1} :count address blocked|[2,*] :count addresses blocked', $stats['active'], ['count' => $stats['active']])]"
            :stats="$headerStats"
        >
            {{-- BlockIp refuses nobody and IpAbuseGuard adds nobody while demo
                 mode is on, so the list is only a record until it is off. --}}
            @if($demoMode)
                <x-slot:state>
                    <span class="ph-state ph-state--warning">{{ __('Demo mode is on: no address is refused and nothing is blocked automatically. The list applies again when demo mode is off.') }}</span>
                </x-slot:state>
            @else
                <x-slot:actions>
                    <button type="button" class="ph-btn ph-btn--primary" data-coreui-toggle="modal" data-coreui-target="#ipaBlockModal">
                        <x-icon name="lock" width="14" height="14"/>{{ __('Block an address') }}
                    </button>
                </x-slot:actions>
            @endif
        </x-page-header>

        <section class="ipa-card">
            <div class="ipa-toolbar">
                <div class="ipa-seg" role="group" aria-label="{{ __('Filter addresses') }}">
                    @foreach(['all' => __('All'), 'active' => __('Refusing'), 'auto' => __('Automatic'), 'lapsed' => __('Lapsed')] as $key => $label)
                        <a href="{{ route('admin.ip-access.index', array_filter(['filter' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}"
                           class="ipa-seg__btn {{ $filter === $key ? 'is-active' : '' }}"
                           @if($filter === $key) aria-current="true" @endif>
                            {{ $label }} <span class="ipa-seg__count">{{ $filterCounts[$key] }}</span>
                        </a>
                    @endforeach
                </div>

                <form method="GET" action="{{ route('admin.ip-access.index') }}" class="ipa-controls">
                    <input type="hidden" name="filter" value="{{ $filter }}">
                    <label for="ipaFind" class="visually-hidden">{{ __('Search addresses') }}</label>
                    <span class="ipa-find">
                        <x-icon name="search" width="14" height="14"/>
                        <input id="ipaFind" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Address or reason') }}">
                    </span>
                    <button type="submit" class="ipa-btn">{{ __('Search') }}</button>
                    @if($search !== '')
                        <a href="{{ route('admin.ip-access.index', ['filter' => $filter]) }}" class="ipa-btn">{{ __('Clear') }}</a>
                    @endif
                </form>
            </div>

            <div class="ipa-scroll">
                <div class="ipa-head">
                    <div>{{ __('Address') }}</div>
                    <div>{{ __('Why it is here') }}</div>
                    <div>{{ __('Strikes') }}</div>
                    <div>{{ __('Blocked') }}</div>
                    <div>{{ __('Expires') }}</div>
                    <div></div>
                </div>

                {{--
                    Held back first. These are the only rows that ask for a
                    decision — the guard passed the threshold and refused to
                    act because several accounts sign in from the address.
                --}}
                @foreach($heldBack as $watch)
                    @include('backend.security.ip-access.partials._held_row', ['watch' => $watch, 'threshold' => $threshold])
                @endforeach

                @forelse($blocks as $block)
                    @include('backend.security.ip-access.partials._block_row', ['block' => $block, 'threshold' => $threshold])
                @empty
                    @if($heldBack === [])
                        <div class="ipa-empty">
                            <x-admin-not-found
                                :title="$search !== '' ? __('Nothing matches that search') : __('No blocked addresses')"
                                :message="$search !== '' ? __('Try the address on its own, or clear the search.') : __('Addresses you block will be refused before they reach a sign-in form, and the guard adds its own here when one keeps being turned away.')"
                                icon="fa-lock"
                            />
                        </div>
                    @endif
                @endforelse
            </div>

            <div class="ipa-foot">
                <span>
                    {{ trans_choice('{0} No addresses|{1} :count address|[2,*] :count addresses', $blocks->total(), ['count' => $blocks->total()]) }}
                    · {{ __('lapsed rows stay as history') }}
                </span>
                {{ $blocks->links() }}
            </div>
        </section>

        <p class="ipa-note">
            <x-icon name="info" width="14" height="14"/>
            <span>
                {{ __(':count weighted refusals inside :window minutes block an address for an hour, doubling on each repeat. Private ranges, and addresses several accounts share, are never blocked automatically — and only a block you wrote yourself is permanent.', ['count' => $threshold, 'window' => $window]) }}
            </span>
        </p>
    </div>

    @include('backend.security.ip-access.partials._block_modal')

    <div class="ipa-backdrop" data-ipa-backdrop></div>
    <aside class="ipa-drawer"
           data-ipa-drawer
           data-ipa-url="{{ route('admin.ip-access.panel') }}"
           data-ipa-loading="{{ __('Loading…') }}"
           data-ipa-failed="{{ __('That address could not be loaded.') }}"
           role="dialog"
           aria-modal="true"
           aria-label="{{ __('Address detail') }}"
           tabindex="-1">
        <div class="ipa-drawer__body" data-ipa-drawer-body>
            <p class="ipa-drawer__loading">{{ __('Loading…') }}</p>
        </div>
    </aside>
@endsection

@push('scripts')
    @php
        $ipaJsVersion = config('app.version') . '-' . filemtime(public_path('backend/js/ip-access.js'));
    @endphp
    <script src="{{ asset('backend/js/ip-access.js?v=' . $ipaJsVersion) }}"></script>
@endpush
