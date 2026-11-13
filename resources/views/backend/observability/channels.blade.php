@extends('backend.layouts.app')
@section('title', __('Notification Channels'))

@section('content')
    @php
        $state     = $alerting['state'];
        $attention = $alerting['attention'];
        $firstFix  = $attention[0] ?? null;
        $names     = collect($attention)->pluck('label')->join(', ', ' '.__('and').' ');

        $pill = match ($state) {
            'down'     => ['label' => __('Failing'), 'tone' => 'danger'],
            'degraded' => ['label' => __('Needs attention'), 'tone' => 'warning'],
            'healthy'  => ['label' => __('Live'), 'tone' => 'success'],
            default    => ['label' => __('Off'), 'tone' => 'neutral'],
        };
    @endphp

    <x-page-header
        :title="__('Notification channels')"
        :subtitle="__('Where a fatal error is sent the moment it happens, so nobody has to be watching this screen.')"
        icon="fa-paper-plane"
        :status="$pill"
        :stats="$headerStats"
        class="mb-3"
    >
        <x-slot:actions>
            <a href="{{ route('admin.settings.site.index', ['section' => 'system_health_settings']) }}" class="ph-btn ph-btn--secondary">
                <i class="fa-solid fa-moon" aria-hidden="true"></i>{{ __('Quiet hours') }}
            </a>

            @can('observability-manage')
                <form method="POST" action="{{ route('admin.observability.channels.test-all') }}" class="d-inline-flex m-0">
                    @csrf
                    <button type="submit"
                            class="ph-btn ph-btn--primary"
                            @if($connected === 0) disabled title="{{ __('Connect a channel first.') }}" @endif>
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>{{ __('Send test alert') }}
                    </button>
                </form>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="observability obs-channels">
        {{--
            The status strip answers the only question this screen exists for:
            if something breaks right now, does anybody find out? A page that
            listed channels without answering it read as configuration rather
            than as a safety net — and one that counted a failing channel as
            "receiving alerts" answered it wrongly.
        --}}
        <div class="obs-strip obs-strip--{{ $state }}" role="status">
            <span class="obs-strip__icon" aria-hidden="true">
                <i class="fa-solid {{ match ($state) {
                    'healthy' => 'fa-bell',
                    'silent'  => 'fa-bell-slash',
                    default   => 'fa-triangle-exclamation',
                } }}"></i>
            </span>

            <span class="obs-strip__body">
                <strong class="obs-strip__title">
                    @switch($state)
                        @case('silent')
                            {{ __('Nobody is being alerted.') }}
                            @break
                        @case('down')
                            {{ __('Nobody is receiving alerts right now.') }}
                            @break
                        @case('degraded')
                            {{ trans_choice('{0}No channel has delivered yet.|{1}:count channel is receiving alerts — the rest need attention.|[2,*]:count channels are receiving alerts — the rest need attention.', $alerting['healthy'], ['count' => $alerting['healthy']]) }}
                            @break
                        @default
                            {{ trans_choice('{1}:count channel is receiving alerts.|[2,*]:count channels are receiving alerts.', $connected, ['count' => $connected]) }}
                    @endswitch
                </strong>
                <span class="obs-strip__note">
                    @switch($state)
                        @case('silent')
                            {{ __('Errors are still captured and still listed. They just stay on this panel until somebody looks.') }}
                            @break
                        @case('down')
                            {{ trans_choice('{1}:names is the only connected channel, and it is rejecting deliveries. Errors are still captured and listed here.|[2,*]Every connected channel is rejecting deliveries: :names. Errors are still captured and listed here.', $connected, ['names' => $names]) }}
                            @break
                        @case('degraded')
                            {{ __('Needs attention: :names. Send a test once it is fixed to confirm delivery.', ['names' => $names]) }}
                            @break
                        @default
                            @if($quiet)
                                {{ __('Quiet hours :window — warnings and errors are held, fatal errors are not.', ['window' => $quiet]) }}
                            @else
                                {{ __('Alerting around the clock. Set quiet hours in System Health settings to hold non-fatal alerts overnight.') }}
                            @endif
                    @endswitch
                </span>
            </span>

            @if(in_array($state, ['down', 'degraded'], true) && $firstFix)
                <a href="#obs-card-{{ $firstFix['key'] }}" class="obs-strip__action">
                    {{ __('Review :channel', ['channel' => $firstFix['label']]) }}
                    <i class="fa-solid fa-chevron-right obs-flip" aria-hidden="true"></i>
                </a>
            @endif
        </div>

        <div class="obs-channels__grid">
            @foreach($cards as $card)
                @include('backend.observability.partials._channel_card', ['card' => $card])
            @endforeach
        </div>

        @include('backend.observability.partials._channel_config')

        <section class="obs-panel" id="delivery-log" aria-labelledby="delivery-log-title">
            <div class="obs-panel__head">
                <div class="obs-panel__intro">
                    <h2 class="obs-panel__title" id="delivery-log-title">{{ __('Delivery log') }}</h2>
                    <p class="obs-panel__lead">
                        {{ __('Held and throttled attempts are listed too. Without them a working throttle and a broken channel look identical.') }}
                    </p>
                </div>

                <nav class="obs-chips" aria-label="{{ __('Filter by status') }}">
                    @foreach([
                        'all'    => __('All'),
                        'sent'   => __('Sent'),
                        'failed' => __('Failed'),
                        'held'   => __('Held'),
                    ] as $filter => $label)
                        <a href="{{ route('admin.observability.channels', $filter === 'all' ? [] : ['log' => $filter]) }}#delivery-log"
                           class="obs-chip @if($logFilter === $filter) is-active @endif"
                           @if($logFilter === $filter) aria-current="true" @endif>
                            {{ $label }}
                            <span class="obs-chip__count">{{ number_format($logCounts[$filter] ?? 0) }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>

            @if($log->isEmpty())
                <div class="obs-panel__body">
                    <x-admin-not-found
                        :title="$logFilter === 'all' ? __('Nothing has been sent yet.') : __('No attempts match this filter.')"
                        :message="__('Every attempt appears here, delivered or not.')"
                        icon="fa-list-check"
                    />
                </div>
            @else
                @include('backend.observability.partials._delivery_log')
            @endif
        </section>
    </div>
@endsection

@include('backend.observability.partials._scripts')
