<section class="wz-screen is-active" data-wz-screen="0" aria-labelledby="wz-title-welcome">
    <div class="wz-hero">
        <div class="wz-hero__copy">
            <span class="wz-hero__pill">
                @include('installer.wizard.icon', ['name' => 'sparkle', 'size' => 12, 'stroke' => 2.5])
                {{ __('Fresh installation') }}@if($serverReport['environment']['version'] !== '') · v{{ $serverReport['environment']['version'] }}@endif
            </span>
            <h1 id="wz-title-welcome" class="wz-hero__title">{{ __('Welcome to DigiKash.') }}<br>{{ __('Let\'s get your platform live.') }}</h1>
            <p class="wz-hero__lead">{{ __('Nine short steps: we verify your server, connect a database, check your license and create your first admin. Nothing is written until you confirm on the review screen.') }}</p>
        </div>
        <div class="wz-hero__time">
            <span class="wz-hero__time-label">
                @include('installer.wizard.icon', ['name' => 'clock', 'size' => 12, 'stroke' => 2.2])
                {{ __('Typical time') }}
            </span>
            <span class="wz-hero__time-value">6–8 <small>{{ __('min') }}</small></span>
        </div>
    </div>

    <div class="wz-grid-3">
        <div class="wz-tile">
            <span class="wz-tile__icon">@include('installer.wizard.icon', ['name' => 'key', 'size' => 18])</span>
            <strong>{{ __('Envato purchase code') }}</strong>
            <span>{{ __('From Downloads → License certificate. One code per production domain.') }}</span>
        </div>
        <div class="wz-tile">
            <span class="wz-tile__icon wz-tone-violet">@include('installer.wizard.icon', ['name' => 'database', 'size' => 18])</span>
            <strong>{{ __('Empty MySQL/MariaDB database') }}</strong>
            <span>{{ __('Host, name, user and password. We can create the database if the user has CREATE.') }}</span>
        </div>
        <div class="wz-tile">
            <span class="wz-tile__icon wz-tone-cyan">@include('installer.wizard.icon', ['name' => 'mail', 'size' => 18])</span>
            <strong>{{ __('Mail credentials (optional)') }}</strong>
            <span>{{ __('Gmail, Mailgun, SES or your host. Skip now and add later from Admin → Settings.') }}</span>
        </div>
    </div>

    <label class="wz-terms">
        <input class="wz-terms__box" type="checkbox" data-wz-terms>
        <span>
            {!! __('I have read and accept the :terms. One license covers one production domain.', ['terms' => '<a href="https://codecanyon.net/licenses/standard" target="_blank" rel="noopener noreferrer">'.e(__('Envato license terms')).'</a>']) !!}
        </span>
    </label>
</section>
