<aside class="wz-side" aria-label="{{ __('Setup steps') }}">
    <div class="wz-brand">
        <div class="wz-brand__identity">
            <svg class="wz-brand__mark" viewBox="0 0 64 64" aria-hidden="true">
                <defs>
                    <linearGradient id="wz-mark" x1="12" y1="8" x2="52" y2="56" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#56a9ff"/>
                        <stop offset="1" stop-color="#2f6ef3"/>
                    </linearGradient>
                </defs>
                <rect x="10" y="8" width="44" height="44" rx="11" fill="url(#wz-mark)"/>
                <path d="M23.5 25.5h17a4 4 0 0 1 4 4v9a4 4 0 0 1-4 4h-17a4 4 0 0 1-4-4v-14a3 3 0 0 1 3-3h13" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M23.5 25.5v-1a3 3 0 0 1 3-3h11" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity=".82"/>
                <path d="M31 31.5h12.5v6H31a3 3 0 0 1 0-6Z" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div class="wz-brand__text">
                <span class="wz-brand__name">DigiKash</span>
                <span class="wz-brand__role">{{ __('Setup Assistant') }}</span>
            </div>
        </div>
        @if($serverReport['environment']['version'] !== '')
            <span class="wz-brand__version">v{{ $serverReport['environment']['version'] }}</span>
        @endif
    </div>

    <div class="wz-progress">
        <div class="wz-progress__meta">
            <span class="wz-progress__label">{{ __('Setup progress') }}</span>
            <span class="wz-progress__value" data-wz-progress-value>0%</span>
        </div>
        <div class="wz-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="{{ __('Setup progress') }}" data-wz-progress>
            <div class="wz-progress__fill" data-wz-progress-fill></div>
        </div>
    </div>

    <ol class="wz-rail">
        @foreach($wizardSteps as $index => $step)
            <li class="wz-rail__item {{ $index === 0 ? 'is-current' : 'is-upcoming' }}" data-wz-rail="{{ $index }}">
                <button class="wz-rail__button" type="button" data-wz-goto="{{ $index }}" @disabled($index > 0)>
                    <span class="wz-rail__icon">
                        @include('installer.wizard.icon', ['name' => $step['icon'], 'size' => 16, 'class' => 'wz-rail__glyph'])
                        @include('installer.wizard.icon', ['name' => 'check', 'size' => 15, 'stroke' => 3, 'class' => 'wz-rail__check'])
                    </span>
                    <span class="wz-rail__text">
                        <span class="wz-rail__head">
                            <span class="wz-rail__title">{{ $step['title'] }}</span>
                            <span class="wz-rail__index">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        </span>
                        <span class="wz-rail__subtitle">{{ $step['subtitle'] }}</span>
                    </span>
                </button>
            </li>
        @endforeach
    </ol>

    <div class="wz-side__foot">
        <div class="wz-env">
            <div class="wz-env__title">
                <span class="wz-env__icon">@include('installer.wizard.icon', ['name' => 'server', 'size' => 14])</span>
                {{ __('Environment detected') }}
            </div>
            <dl class="wz-env__grid">
                <dt>PHP</dt>
                <dd>{{ $serverReport['environment']['php'] }}</dd>
                <dt>{{ __('Server') }}</dt>
                <dd title="{{ $serverReport['environment']['server'] }}">{{ $serverReport['environment']['server'] }}</dd>
                <dt>Laravel</dt>
                <dd>{{ $serverReport['environment']['laravel'] }}</dd>
                <dt>{{ __('Domain') }}</dt>
                <dd title="{{ $serverReport['environment']['domain'] }}">{{ $serverReport['environment']['domain'] }}</dd>
            </dl>
        </div>
        <div class="wz-side__links">
            <a class="wz-side__link" href="{{ $links['docs'] ?? '#' }}" target="_blank" rel="noopener noreferrer">
                @include('installer.wizard.icon', ['name' => 'book', 'size' => 14])
                {{ __('Docs') }}
            </a>
            <a class="wz-side__link" href="{{ $links['support'] ?? '#' }}" target="_blank" rel="noopener noreferrer">
                @include('installer.wizard.icon', ['name' => 'lifebuoy', 'size' => 14])
                {{ __('Support') }}
            </a>
        </div>
    </div>
</aside>
