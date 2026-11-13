@extends('backend.layouts.app')

@section('title', __('System information'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/system-info.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/system-info.css'))) }}">
@endpush

@section('content')
    @php
        /*
         * One page, read top to bottom: the health overview says what is wrong
         * and what to do, the runtime stack says what this runs on, and the
         * cards below hold the remaining facts — each fact in exactly one
         * place. The controller types everything; this file only draws it.
         *
         * Icons are inline strokes rather than FontAwesome: the shipped
         * webfont is subsetted, and a class it lacks draws an empty tile.
         */
        $permissions     = session('admin_permissions', []);
        $canClearCache   = Route::has('admin.app.clear-cache') && in_array('app-clear-cache', $permissions, true);
        $canOptimize     = Route::has('admin.app.optimize') && in_array('app-optimize', $permissions, true);
        $canStyleManager = Route::has('admin.app.style-manager') && in_array('style-manager', $permissions, true);
        $canOpenJobs     = Route::has('admin.background-tasks.index') && in_array('background-task-list', $permissions, true);

        $paths = [
            'check'    => '<path d="M20 6 9 17l-5-5"/>',
            'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
            'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
            'alert'    => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
            'up'       => '<circle cx="12" cy="12" r="9"/><path d="M12 16V8M8 12l4-4 4 4"/>',
            'eye-off'  => '<path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/>',
            'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'layers'   => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
            'shield-ok'=> '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="m9 12 2 2 4-4"/>',
            'shield'   => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/>',
            'key'      => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2M17 6l3 3M14 9l2 2"/>',
            'bug'      => '<path d="M9 7.1V6a3 3 0 0 1 6 0v1.1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6zM12 20v-9M6 13H2M22 13h-4"/>',
            'chip'     => '<rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 9h6v6H9zM9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
            'sliders'  => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
            'terminal' => '<path d="m4 17 6-6-6-6M12 19h8"/>',
            'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
            'code'     => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
            'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'box'      => '<path d="m21 8-9-5-9 5v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
            'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'copy'     => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'chevron'  => '<path d="m9 18 6-6-6-6"/>',
            'trash'    => '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M6 6l1 14a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-14"/>',
            'zap'      => '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
            'gauge'    => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
            'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'server'   => '<rect x="3" y="3.5" width="18" height="7" rx="2"/><rect x="3" y="13.5" width="18" height="7" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
            'wrench'   => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9l-3.8 3.8z"/>',
            'timer'    => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M10 2h4"/>',
            'upload'   => '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
            'file-up'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15l3-3 3 3"/>',
            'puzzle'   => '<path d="M15 4.5a2.5 2.5 0 0 0-5 0V6H6a1 1 0 0 0-1 1v4h1.5a2.5 2.5 0 0 1 0 5H5v4a1 1 0 0 0 1 1h4v-1.5a2.5 2.5 0 0 1 5 0V21h4a1 1 0 0 0 1-1v-4h-1.5a2.5 2.5 0 0 1 0-5H20V7a1 1 0 0 0-1-1h-4z"/>',
            'braces'   => '<path d="M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5a2 2 0 0 0 2 2h1M16 21h1a2 2 0 0 0 2-2v-5a2 2 0 0 1 2-2 2 2 0 0 1-2-2V5a2 2 0 0 0-2-2h-1"/>',
            'tag'      => '<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z"/><path d="M7.5 7.5h.01"/>',
            'network'  => '<rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3M12 12V8"/>',
            'folder'   => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9l-.8-1.2A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/>',
            'list'     => '<path d="M3 6h.01M3 12h.01M3 18h.01M8 6h13M8 12h13M8 18h13"/>',
            'cookie'   => '<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5h.01M16 15.5h.01M12 12h.01M11 17h.01M7 14h.01"/>',
            'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'language' => '<path d="m5 8 6 6M4 14l6-6 2-3M2 5h12M7 2h1M22 22l-5-10-5 10M14 18h6"/>',
            'inbox'    => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
        ];

        /* Glyphs keyed by the controller's own keys, never by a translated label. */
        $navGlyph = ['overview' => 'gauge', 'stack' => 'layers', 'security' => 'shield', 'php' => 'chip', 'drivers' => 'sliders', 'services' => 'activity', 'host' => 'server'];
        $rowGlyph = [
            'memory'   => 'chip',     'execution' => 'timer',  'upload'   => 'upload',   'post'    => 'file-up',
            'database' => 'database', 'cache'     => 'zap',    'queue'    => 'list',     'session' => 'cookie',
            'storage'  => 'folder',   'timezone'  => 'clock',  'language' => 'language',
            'hostname' => 'tag',      'ip'        => 'network',
        ];

        $glyph = static fn (string $name, int $size = 16, float $stroke = 2): string => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'.$stroke.'" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$paths[$name].'</svg>';

        /*
         * A value demo mode withholds, drawn the same way everywhere: a lock
         * and a redaction bar where the value would be, so the layout keeps
         * its shape and the eye reads "hidden on purpose", not "missing".
         * Screen readers hear "Protected"; the copied report says the same.
         */
        $redact = static fn (string $size = 'md'): string => '<span class="sys-redact sys-redact--'.$size.'" title="'.e(__('Hidden in demo mode')).'">'
            .$glyph('lock', $size === 'lg' ? 13 : 11, 2.4)
            .'<span class="sys-redact__bar" aria-hidden="true"></span>'
            .'<span class="visually-hidden">'.e(__('Protected')).'</span></span>';

        $hiddenInDemo = [
            ['glyph' => 'layers',   'label' => __('Exact versions')],
            ['glyph' => 'globe',    'label' => __('Web server & PHP handler')],
            ['glyph' => 'chip',     'label' => __('PHP limits')],
            ['glyph' => 'puzzle',   'label' => __('Extensions & functions')],
            ['glyph' => 'key',      'label' => __('App key & debug mode')],
            ['glyph' => 'sliders',  'label' => __('Drivers & timezone')],
            ['glyph' => 'activity', 'label' => __('Queue details')],
            ['glyph' => 'server',   'label' => __('Hostname & IP address')],
        ];

        $checkGlyph = ['app_key' => 'key', 'https' => 'lock', 'csrf' => 'shield', 'debug' => 'bug'];
        $issueGlyph = ['danger' => 'x-circle', 'warning' => 'alert', 'info' => 'up'];
        $flagOf     = collect($nav)->pluck('flag', 'key');
        $securityTone  = $flagOf['security'] ?? ($isDemo ? 'brand' : 'success');
        $securityLabel = match (true) {
            $flagOf['security'] !== null => __('Needs attention'),
            $isDemo                      => __('Partly hidden'),
            default                      => __('All pass'),
        };
        $phpTone    = $isDemo ? 'neutral' : ($flagOf['php'] ?? 'success');
        $severities = [
            'danger'  => fn (int $count): string => trans_choice(':count critical|:count critical', $count, ['count' => $count]),
            'warning' => fn (int $count): string => trans_choice(':count warning|:count warnings', $count, ['count' => $count]),
            'info'    => fn (int $count): string => trans_choice(':count notice|:count notices', $count, ['count' => $count]),
        ];
        $shownSeverities = array_filter($health['counts']);
    @endphp

    <x-page-header
        :title="__('System information')"
        :eyebrow="__('Platform')"
        :subtitle="__('Whether this installation is healthy, what it runs on, and what to fix first. Copy the report into a support ticket when something breaks.')"
        icon="sidebar-server"
        tile="soft"
        :status="['tone' => $environment['live'] ? 'success' : 'warning', 'label' => $environment['label']]"
        :meta="[
            ['label' => __('Checked :when', ['when' => $checkedAt->format('j M Y, H:i')])],
            ['label' => __('Re-checked every time this page opens')],
        ]"
    >
        <x-slot:actions>
            <a class="ph-btn ph-btn--secondary" href="{{ route('admin.app.info') }}">
                <x-icon name="repeat" width="14" height="14"/>{{ __('Re-check') }}
            </a>
            <button type="button" class="ph-btn ph-btn--primary" data-sys-copy-report
                    data-url="{{ route('admin.app.info.report') }}"
                    data-done="{{ __('Report copied — paste it into your support ticket.') }}"
                    data-fail="{{ __('Could not copy. Open the report as text and copy it from there.') }}">
                <x-icon name="clipboard" width="14" height="14"/>{{ __('Copy report') }}
            </button>
        </x-slot:actions>

        <x-slot:menu>
            <a class="dropdown-item" href="{{ route('admin.app.info.report') }}" target="_blank" rel="noopener">
                <span class="ph-menu__tile" aria-hidden="true"><x-icon name="sidebar-page" width="15" height="15"/></span>
                <span class="ph-menu__copy">
                    <span class="ph-menu__label">{{ __('Open report as text') }}</span>
                    <span class="ph-menu__hint">{{ __('The same facts, in a new tab') }}</span>
                </span>
            </a>
            @if(Route::has('admin.app.control-panel'))
                <a class="dropdown-item" href="{{ route('admin.app.control-panel') }}">
                    <span class="ph-menu__tile" aria-hidden="true"><x-icon name="sidebar-apps" width="15" height="15"/></span>
                    <span class="ph-menu__copy">
                        <span class="ph-menu__label">{{ __('Control panel') }}</span>
                        <span class="ph-menu__hint">{{ __('Every module in one launcher') }}</span>
                    </span>
                </a>
            @endif
            @if($canStyleManager)
                <a class="dropdown-item" href="{{ route('admin.app.style-manager') }}">
                    <span class="ph-menu__tile" aria-hidden="true"><x-icon name="sidebar-palette" width="15" height="15"/></span>
                    <span class="ph-menu__copy">
                        <span class="ph-menu__label">{{ __('Style manager') }}</span>
                        <span class="ph-menu__hint">{{ __('Custom CSS for the storefront') }}</span>
                    </span>
                </a>
            @endif
        </x-slot:menu>
    </x-page-header>

    <div class="sys">
        {{--
            Jump links, not tabs: every card is on the page, so a link is all a
            section needs. The dot repeats nothing — it says which card holds
            the problem the overview names.
        --}}
        <nav class="sys-nav" aria-label="{{ __('Sections') }}" data-sys-nav>
            @foreach($nav as $item)
                <a class="sys-nav__link" href="#sys-{{ $item['key'] }}" data-sys-nav-link="{{ $item['key'] }}">
                    <span class="sys-nav__icon">{!! $glyph($navGlyph[$item['key']] ?? 'layers', 15) !!}</span>
                    {{ $item['label'] }}
                    @if($item['flag'])
                        <span class="sys-dot sys-dot--{{ $item['flag'] }}" aria-hidden="true"></span>
                        <span class="visually-hidden">{{ __('needs attention') }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <section class="sys-card sys-ov" id="sys-overview" aria-labelledby="sys-ov-verdict">
            <div class="sys-ov__score">
                <span class="sys-overline">{{ __('Health') }}</span>
                <div class="sys-ring">
                    <svg width="148" height="148" viewBox="0 0 148 148" aria-hidden="true" focusable="false">
                        <circle class="sys-ring__track" cx="74" cy="74" r="62"></circle>
                        <circle class="sys-ring__arc sys-ring__arc--{{ $health['tone'] }}" cx="74" cy="74" r="62"
                                stroke-dasharray="{{ $health['arc'] }} {{ $health['circumference'] }}"
                                transform="rotate(-90 74 74)"></circle>
                    </svg>
                    <span class="sys-ring__center">
                        <span class="sys-ring__num" dir="ltr">{{ $health['passed'] }}<span class="sys-ring__den">/{{ $health['total'] }}</span></span>
                        <span class="sys-ring__cap">{{ $health['caption'] }}</span>
                    </span>
                </div>
                <h2 class="sys-ov__verdict" id="sys-ov-verdict">{{ $health['verdict'] }}</h2>
                <ul class="sys-legend">
                    @forelse($shownSeverities as $severity => $count)
                        <li><span class="sys-dot sys-dot--{{ $severity }}" aria-hidden="true"></span>{{ $severities[$severity]($count) }}</li>
                    @empty
                        <li><span class="sys-dot sys-dot--success" aria-hidden="true"></span>{{ __('No open issues') }}</li>
                    @endforelse
                </ul>
            </div>

            <div class="sys-ov__main">
                @if($isDemo)
                    <div class="sys-ov__head">
                        <span class="sys-card__icon sys-tone--brand">{!! $glyph('shield-ok', 18) !!}</span>
                        <div>
                            <div class="sys-card__titleline">
                                <h3 class="sys-card__title">{{ __('Demo mode protects this server') }}</h3>
                                <span class="sys-pill sys-tone--brand" data-sys-protected="{{ $protectedCount }}">{!! $glyph('lock', 12, 2.4) !!}{{ trans_choice(':count value hidden|:count values hidden', $protectedCount, ['count' => $protectedCount]) }}</span>
                            </div>
                            <p class="sys-card__sub">{{ __('Checks still run. What they read stays hidden here and in the copied report.') }}</p>
                        </div>
                    </div>
                    <div class="sys-shield">
                        <p class="sys-shield__lead">{{ __('Exact versions, addresses and settings are what a scanner matches against published vulnerabilities, so a public demo keeps them back.') }}</p>
                        <ul class="sys-shield__list">
                            @foreach($hiddenInDemo as $hidden)
                                <li class="sys-shield__item"><span class="sys-shield__icon">{!! $glyph($hidden['glyph'], 14) !!}</span>{{ $hidden['label'] }}</li>
                            @endforeach
                        </ul>
                        <p class="sys-shield__foot">{!! $glyph('eye-off', 14) !!}{{ __('Turn demo mode off in the environment file to see every value.') }}</p>
                    </div>
                @endif

                @if($issues !== [])
                    <div class="sys-ov__head">
                        <span class="sys-card__icon sys-tone--neutral">{!! $glyph('inbox', 18) !!}</span>
                        <div>
                            <h3 class="sys-card__title">{{ __('What needs you') }}</h3>
                            <p class="sys-card__sub">{{ __('Most urgent first. Each item says what to do about it.') }}</p>
                        </div>
                        <span class="sys-pill sys-tone--{{ $issues[0]['severity'] }} sys-ov__aside">{{ trans_choice(':count open|:count open', count($issues), ['count' => count($issues)]) }}</span>
                    </div>
                    <ul class="sys-issues">
                        @foreach($issues as $issue)
                            <li class="sys-issue">
                                <span class="sys-issue__icon sys-tone--{{ $issue['severity'] }}">{!! $glyph($issueGlyph[$issue['severity']], 18) !!}</span>
                                <div class="sys-issue__copy">
                                    <span class="sys-issue__sev sys-issue__sev--{{ $issue['severity'] }}">{{ $issue['severityLabel'] }} · {{ $issue['area'] }}</span>
                                    <p class="sys-issue__title">{{ $issue['title'] }}</p>
                                    <p class="sys-issue__text">{{ $issue['text'] }}</p>
                                </div>
                                @if($issue['action'])
                                    <a class="sys-action" href="{{ $issue['action']['url'] }}">{{ $issue['action']['label'] }}<span class="sys-chev">{!! $glyph('chevron', 15) !!}</span></a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @elseif(! $isDemo)
                    <div class="sys-ov__head">
                        <span class="sys-card__icon sys-tone--neutral">{!! $glyph('inbox', 18) !!}</span>
                        <div>
                            <h3 class="sys-card__title">{{ __('What needs you') }}</h3>
                            <p class="sys-card__sub">{{ __('Problems the checks find appear here, most urgent first.') }}</p>
                        </div>
                    </div>
                    <div class="sys-clear">
                        <span class="sys-clear__icon sys-tone--success">{!! $glyph('shield-ok', 26, 2.2) !!}</span>
                        <div>
                            <p class="sys-clear__title">{{ __('Nothing needs you right now') }}</p>
                            <p class="sys-clear__text">{{ __('Security, PHP requirements and background services all passed, and no newer release is waiting.') }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <section class="sys-card" id="sys-stack" aria-labelledby="sys-stack-title">
            <header class="sys-card__head">
                <span class="sys-card__icon sys-tone--brand">{!! $glyph('layers', 18) !!}</span>
                <div>
                    <h2 class="sys-card__title" id="sys-stack-title">{{ __('Runtime stack') }}</h2>
                    <p class="sys-card__sub">{{ __('From the machine up to DigiKash, checked against what this release requires.') }}</p>
                </div>
            </header>
            <div class="sys-stack">
                @foreach($stack as $layer)
                    <div @class(['sys-layer', 'sys-layer--app' => $layer['isApp']]) data-sys-layer="{{ $layer['key'] }}">
                        <div class="sys-layer__top">
                            <span class="sys-layer__role">{{ $layer['role'] }}</span>
                            <span class="sys-layer__glyph">{!! $glyph($layer['icon'], 15) !!}</span>
                        </div>
                        <span class="sys-layer__name">{{ $layer['name'] }}</span>
                        <span class="sys-layer__ver">
                            @if($layer['version'] === null)
                                {!! $redact() !!}
                            @else
                                <span class="sys-mono" dir="ltr">{{ $layer['version'] === '' ? '—' : $layer['version'] }}</span>
                                @if($layer['copy'])
                                    <button type="button" class="sys-copy" data-sys-copy-value="{{ $layer['version'] }}" aria-label="{{ __('Copy :what', ['what' => $layer['name'].' '.__('version')]) }}">{!! $glyph('copy', 14) !!}{!! $glyph('check', 14, 2.6) !!}</button>
                                @endif
                            @endif
                        </span>
                        <span class="sys-layer__foot sys-layer__foot--{{ $layer['tone'] }}">
                            @if($layer['detail'] === null)
                                {!! $redact('sm') !!}
                            @else
                                @if($layer['tone'] === 'success')
                                    {!! $glyph('check', 12, 3) !!}
                                @elseif($layer['tone'] === 'danger')
                                    {!! $glyph('x', 12, 3) !!}
                                @endif
                                <span dir="auto">{{ $layer['detail'] }}</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="sys-cols">
            <div class="sys-cols__main">
                <section class="sys-card" id="sys-security" aria-labelledby="sys-security-title">
                    <header class="sys-card__head sys-card__head--ruled">
                        <span class="sys-card__icon sys-tone--{{ $securityTone }}">{!! $glyph('shield-ok', 18) !!}</span>
                        <div>
                            <div class="sys-card__titleline">
                                <h2 class="sys-card__title" id="sys-security-title">{{ __('Security posture') }}</h2>
                                <span class="sys-pill sys-tone--{{ $securityTone }}">{{ $securityLabel }}</span>
                            </div>
                            <p class="sys-card__sub">{{ __('The settings that decide what a visitor can see or forge.') }}</p>
                        </div>
                    </header>
                    <ul class="sys-checks">
                        @foreach($security as $check)
                            <li class="sys-check">
                                <span class="sys-check__icon">{!! $glyph($checkGlyph[$check['key']] ?? 'shield', 16) !!}</span>
                                <div>
                                    <p class="sys-check__title">{{ $check['label'] }}</p>
                                    <p class="sys-check__text">{{ $check['detail'] }}</p>
                                </div>
                                @if($check['state'] === null)
                                    {!! $redact() !!}
                                @else
                                    <span class="sys-pill sys-tone--{{ $check['tone'] }}">{{ $check['state'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="sys-card" id="sys-php" aria-labelledby="sys-php-title">
                    <header class="sys-card__head">
                        <span class="sys-card__icon sys-tone--{{ $phpTone }}">{!! $glyph('chip', 18) !!}</span>
                        <div>
                            <h2 class="sys-card__title" id="sys-php-title">{{ __('PHP limits & requirements') }}</h2>
                            <p class="sys-card__sub">{{ __('How much one request may use, and the modules DigiKash depends on.') }}</p>
                        </div>
                    </header>
                    <div class="sys-limits">
                        @foreach($limits as $limit)
                            <div class="sys-limit">
                                <span class="sys-limit__label"><span class="sys-limit__icon">{!! $glyph($rowGlyph[$limit['key']] ?? 'chip', 14) !!}</span>{{ $limit['label'] }}</span>
                                @if($limit['value'] === null)
                                    <span>{!! $redact('lg') !!}</span>
                                @else
                                    <span class="sys-limit__value" dir="ltr">{{ $limit['value'] }}<span class="sys-limit__unit">{{ $limit['unit'] }}</span></span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="sys-subhead">
                        <span class="sys-subhead__title"><span class="sys-subhead__icon">{!! $glyph('puzzle', 15) !!}</span>{{ __('Extensions') }}</span>
                        <span class="sys-subhead__note">{{ __('The installer’s list plus every installed add-on’s') }}</span>
                        @if($extensions !== null && $extensions !== [])
                            <div class="sys-seg" role="group" aria-label="{{ __('Filter extensions') }}" data-sys-ext-filter>
                                <button type="button" class="sys-seg__btn is-on" aria-pressed="true" data-sys-ext-show="all">{{ __('All') }} · <span dir="ltr">{{ count($extensions) }}</span></button>
                                <button type="button" class="sys-seg__btn" aria-pressed="false" data-sys-ext-show="missing">{{ __('Missing') }} · <span dir="ltr">{{ count(array_filter($extensions, static fn (array $chip): bool => ! $chip['ok'])) }}</span></button>
                            </div>
                        @endif
                    </div>
                    @if($extensions === null)
                        <p class="sys-empty">{!! $redact() !!}{{ __('The extension list is hidden in demo mode.') }}</p>
                    @else
                        <ul class="sys-chips" data-sys-ext-list>
                            @foreach($extensions as $chip)
                                <li @class(['sys-chip', 'sys-chip--missing' => ! $chip['ok']]) data-ok="{{ $chip['ok'] ? '1' : '0' }}"
                                    title="{{ $chip['ok'] ? __('Loaded') : ($chip['needed_by'] ? __('Not loaded · needed by :addon', ['addon' => $chip['needed_by']]) : __('Not loaded')) }}">
                                    {!! $glyph($chip['ok'] ? 'check' : 'alert', 12, 2.8) !!}<span dir="ltr">{{ $chip['name'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="sys-empty sys-empty--ok" data-sys-ext-none hidden>{!! $glyph('check', 16, 2.4) !!}{{ __('Every required extension is loaded.') }}</p>
                    @endif

                    <div class="sys-subhead">
                        <span class="sys-subhead__title"><span class="sys-subhead__icon">{!! $glyph('braces', 15) !!}</span>{{ __('Functions') }}</span>
                        <span class="sys-subhead__note">{{ __('Must not be listed in disable_functions') }}</span>
                    </div>
                    @if($functions === null)
                        <p class="sys-empty">{!! $redact() !!}{{ __('Hidden in demo mode.') }}</p>
                    @else
                        <ul class="sys-chips">
                            @foreach($functions as $chip)
                                <li @class(['sys-chip', 'sys-chip--missing' => ! $chip['ok']]) title="{{ $chip['hint'] }}">
                                    {!! $glyph($chip['ok'] ? 'check' : 'alert', 12, 2.8) !!}<span dir="ltr">{{ $chip['name'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="sys-card" id="sys-drivers" aria-labelledby="sys-drivers-title">
                    <header class="sys-card__head">
                        <span class="sys-card__icon sys-tone--neutral">{!! $glyph('sliders', 18) !!}</span>
                        <div>
                            <h2 class="sys-card__title" id="sys-drivers-title">{{ __('Drivers & locale') }}</h2>
                            <p class="sys-card__sub">{{ __('Where data, cache and sessions live, and how dates are shown.') }}</p>
                        </div>
                    </header>
                    <div class="sys-kv">
                        @foreach([__('Drivers') => $drivers, __('Locale') => $locale] as $group => $rows)
                            <div class="sys-kv__group">
                                <p class="sys-overline sys-kv__heading">{{ $group }}</p>
                                <dl class="sys-kv__list">
                                    @foreach($rows as $row)
                                        <div class="sys-kv__row">
                                            <dt><span class="sys-kv__icon">{!! $glyph($rowGlyph[$row['key']] ?? 'sliders', 15) !!}</span>{{ $row['label'] }}</dt>
                                            <dd>
                                                @if($row['value'] === null)
                                                    {!! $redact() !!}
                                                @else
                                                    <span class="sys-code" dir="ltr">{{ $row['value'] }}</span>
                                                @endif
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="sys-cols__rail">
                <section class="sys-card" id="sys-services" aria-labelledby="sys-services-title">
                    <header class="sys-card__head sys-card__head--ruled">
                        <span class="sys-card__icon sys-tone--{{ $services['tone'] === 'neutral' ? 'brand' : $services['tone'] }}">{!! $glyph('activity', 18) !!}</span>
                        <div>
                            <div class="sys-card__titleline">
                                <h2 class="sys-card__title" id="sys-services-title">{{ __('Background services') }}</h2>
                                <span class="sys-pill sys-tone--{{ $services['tone'] }}"><span class="sys-pill__dot" aria-hidden="true"></span>{{ $services['label'] }}</span>
                            </div>
                            <p class="sys-card__sub">{{ __('What runs when nobody is clicking') }}</p>
                        </div>
                    </header>
                    <ul class="sys-services">
                        @foreach($services['rows'] as $service)
                            <li class="sys-service">
                                <span class="sys-service__dot sys-service__dot--{{ $service['tone'] }}" aria-hidden="true"></span>
                                <div>
                                    <p class="sys-service__name">{{ $service['label'] }}</p>
                                    @if($service['detail'] === null)
                                        <p class="sys-service__detail">{!! $redact('sm') !!}</p>
                                    @else
                                        <p class="sys-service__detail">{{ $service['detail'] }}</p>
                                    @endif
                                </div>
                                <span class="sys-figure sys-tone--{{ $service['tone'] }}">{{ $service['state'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if($canOpenJobs)
                        <a class="sys-card__link" href="{{ route('admin.background-tasks.index') }}">{{ __('Open background jobs') }}<span class="sys-chev">{!! $glyph('chevron', 15) !!}</span></a>
                    @endif
                </section>

                <section class="sys-card" id="sys-host" aria-labelledby="sys-host-title">
                    <header class="sys-card__head sys-card__head--ruled">
                        <span class="sys-card__icon sys-tone--neutral">{!! $glyph('server', 18) !!}</span>
                        <div>
                            <h2 class="sys-card__title" id="sys-host-title">{{ __('Host') }}</h2>
                            <p class="sys-card__sub">{{ __('The machine serving this request') }}</p>
                        </div>
                    </header>
                    <ul class="sys-host">
                        @foreach($host as $row)
                            <li class="sys-host__row">
                                <span class="sys-host__icon">{!! $glyph($rowGlyph[$row['key']] ?? 'server', 15) !!}</span>
                                <div class="sys-host__text">
                                    <span class="sys-host__key">{{ $row['label'] }}</span>
                                    @if($row['value'] === null)
                                        <span>{!! $redact() !!}</span>
                                    @else
                                        <span class="sys-host__val" dir="ltr">{{ $row['value'] }}</span>
                                    @endif
                                </div>
                                @if($row['value'] !== null)
                                    <button type="button" class="sys-copy" data-sys-copy-value="{{ $row['value'] }}" aria-label="{{ __('Copy :what', ['what' => $row['label']]) }}">{!! $glyph('copy', 14) !!}{!! $glyph('check', 14, 2.6) !!}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                @if($canClearCache || $canOptimize)
                    <section class="sys-card" aria-labelledby="sys-maint-title">
                        <header class="sys-card__head">
                            <span class="sys-card__icon sys-tone--neutral">{!! $glyph('wrench', 18) !!}</span>
                            <div>
                                <h2 class="sys-card__title" id="sys-maint-title">{{ __('Maintenance') }}</h2>
                                <p class="sys-card__sub">{{ __('Safe to run at any time') }}</p>
                            </div>
                        </header>
                        <div class="sys-maint">
                            @if($canClearCache)
                                <a class="sys-maint__row" href="{{ route('admin.app.clear-cache') }}">
                                    <span class="sys-maint__icon">{!! $glyph('trash', 16) !!}</span>
                                    <span><span class="sys-maint__name">{{ __('Clear cache') }}</span><span class="sys-maint__detail">{{ __('Config, routes and compiled views') }}</span></span>
                                    <span class="sys-chev sys-maint__go">{!! $glyph('chevron', 15) !!}</span>
                                </a>
                            @endif
                            @if($canOptimize)
                                <a class="sys-maint__row" href="{{ route('admin.app.optimize') }}">
                                    <span class="sys-maint__icon">{!! $glyph('zap', 16) !!}</span>
                                    <span><span class="sys-maint__name">{{ __('Optimize app') }}</span><span class="sys-maint__detail">{{ __('Rebuilds the config, route and view caches') }}</span></span>
                                    <span class="sys-chev sys-maint__go">{!! $glyph('chevron', 15) !!}</span>
                                </a>
                            @endif
                        </div>
                    </section>
                @endif
            </aside>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('backend/js/system-info.js?v='.config('app.version').'-'.filemtime(public_path('backend/js/system-info.js'))) }}"></script>
@endpush
