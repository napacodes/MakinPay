@php
    $wizardDriver = old('db_connection', $defaults['db_connection']);
    $wizardDriver = in_array($wizardDriver, ['mysql', 'mariadb', 'sqlite'], true) ? $wizardDriver : 'mysql';
@endphp
<section class="wz-screen" data-wz-screen="4" aria-labelledby="wz-title-database" data-wz-database>
    <div class="wz-head">
        <div class="wz-head__identity">
            <span class="wz-head__icon">@include('installer.wizard.icon', ['name' => 'database', 'size' => 22])</span>
            <div class="wz-head__copy">
                <h2 id="wz-title-database">{{ __('Database') }}</h2>
                <p>{{ __('Point us at an empty MySQL or MariaDB database. We\'ll create it if the user has permission, then import the schema and reference data.') }}</p>
            </div>
        </div>
    </div>

    <div class="wz-card wz-card--form wz-stack">
        <fieldset class="wz-field wz-fieldset">
            <legend class="wz-label"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'layers', 'size' => 14]){{ __('Driver') }}</span></legend>
            <div class="wz-segment" role="radiogroup">
                @foreach(['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'sqlite' => 'SQLite'] as $driver => $driverLabel)
                    <label class="wz-segment__option">
                        <input type="radio" name="db_connection" value="{{ $driver }}" @checked($wizardDriver === $driver) data-wz-driver>
                        <span>{{ $driverLabel }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="wz-grid-6" data-wz-enter="[data-wz-db-test]">
            <div class="wz-field wz-col-4" data-wz-server-field>
                <label class="wz-label" for="db_host"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'server', 'size' => 14]){{ __('Host') }}</span></label>
                <input class="wz-input wz-input--mono" id="db_host" name="db_host" value="{{ old('db_host', $defaults['db_host']) }}" required maxlength="255" autocapitalize="off" spellcheck="false" data-wz-db-field>
            </div>
            <div class="wz-field wz-col-2" data-wz-server-field>
                <label class="wz-label" for="db_port"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'hash', 'size' => 14]){{ __('Port') }}</span></label>
                <input class="wz-input wz-input--mono" id="db_port" name="db_port" type="number" min="1" max="65535" value="{{ old('db_port', $defaults['db_port']) }}" required data-wz-db-field>
            </div>
            <div class="wz-field wz-col-6">
                <label class="wz-label" for="db_database"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'database', 'size' => 14])<span data-wz-db-name-label data-wz-label-server="{{ __('Database name') }}" data-wz-label-sqlite="{{ __('Database file') }}">{{ __('Database name') }}</span></span></label>
                <input class="wz-input wz-input--mono" id="db_database" name="db_database" value="{{ old('db_database', $defaults['db_database']) }}" required maxlength="255" autocapitalize="off" spellcheck="false" data-wz-db-field data-wz-db-name>
                <span class="wz-help" data-wz-db-name-help data-wz-help-server="{{ __('Created automatically if it doesn\'t exist and the user has CREATE.') }}" data-wz-help-sqlite="{{ __('A file path relative to the project, created if it does not exist.') }}">{{ __('Created automatically if it doesn\'t exist and the user has CREATE.') }}</span>
            </div>
            <div class="wz-field wz-col-3" data-wz-server-field>
                <label class="wz-label" for="db_username"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'user', 'size' => 14]){{ __('Username') }}</span></label>
                <input class="wz-input wz-input--mono" id="db_username" name="db_username" value="{{ old('db_username', $defaults['db_username']) }}" required maxlength="255" autocapitalize="off" spellcheck="false" autocomplete="off" data-wz-db-field>
            </div>
            <div class="wz-field wz-col-3" data-wz-server-field>
                <label class="wz-label" for="db_password"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'lock', 'size' => 14]){{ __('Password') }}</span></label>
                <input class="wz-input" id="db_password" name="db_password" type="password" maxlength="255" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-wz-db-field>
            </div>
        </div>

        <div class="wz-status" data-wz-db-status aria-live="polite">
            <div class="wz-status__main">
                <span class="wz-status__icon">
                    <span class="wz-status__idle">@include('installer.wizard.icon', ['name' => 'database', 'size' => 16])</span>
                    <span class="wz-status__check">@include('installer.wizard.icon', ['name' => 'check', 'size' => 16, 'stroke' => 3])</span>
                    <span class="wz-status__cross">@include('installer.wizard.icon', ['name' => 'alert', 'size' => 16, 'stroke' => 2.2])</span>
                </span>
                <span class="wz-status__text">{{ __('Not tested yet — required to continue') }}</span>
            </div>
            <button class="wz-btn wz-btn--primary" type="button" data-wz-db-test>
                @include('installer.wizard.icon', ['name' => 'zap', 'size' => 14])
                <span>{{ __('Test connection') }}</span>
            </button>
        </div>
        <ul class="wz-guidance" data-wz-db-guidance hidden></ul>
    </div>

    <div class="wz-card wz-report" data-wz-db-report hidden>
        <div class="wz-card__head wz-report__head">
            <span class="wz-card__title">@include('installer.wizard.icon', ['name' => 'server', 'size' => 15])<span data-wz-db-report-title></span></span>
        </div>
        <div data-wz-db-report-rows></div>
    </div>
</section>
