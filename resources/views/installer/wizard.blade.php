@php
    $wizardSteps = [
        ['key' => 'welcome', 'title' => __('Welcome'), 'subtitle' => __('License terms and overview'), 'icon' => 'home'],
        ['key' => 'server', 'title' => __('Server check'), 'subtitle' => __('PHP, extensions, permissions'), 'icon' => 'server'],
        ['key' => 'application', 'title' => __('Application'), 'subtitle' => __('Name, URL, currency, timezone'), 'icon' => 'globe'],
        ['key' => 'license', 'title' => __('License'), 'subtitle' => __('Envato purchase code'), 'icon' => 'key'],
        ['key' => 'database', 'title' => __('Database'), 'subtitle' => __('Connection and import'), 'icon' => 'database'],
        ['key' => 'admin', 'title' => __('Super admin'), 'subtitle' => __('Owner account'), 'icon' => 'user'],
        ['key' => 'email', 'title' => __('Email'), 'subtitle' => __('SMTP provider and test'), 'icon' => 'mail'],
        ['key' => 'options', 'title' => __('Options'), 'subtitle' => __('Demo data, queue, cron'), 'icon' => 'sliders'],
        ['key' => 'review', 'title' => __('Review'), 'subtitle' => __('Confirm and install'), 'icon' => 'clipboard'],
    ];

    $wizardClient = [
        'routes' => [
            'requirements' => route('install.requirements'),
            'license'      => route('install.license.test'),
            'database'     => route('install.database.test'),
            'smtp'         => route('install.smtp.test'),
            'install'      => route('install.store'),
        ],
        'steps'         => array_map(static fn (array $step): array => ['key' => $step['key'], 'title' => $step['title'], 'icon' => $step['icon']], $wizardSteps),
        'canInstall'    => $serverReport['can_install'],
        'appUrl'        => $defaults['app_url'],
        'domain'        => $serverReport['environment']['domain'],
        'adminPrefix'   => $defaults['admin_prefix'],
        'mailProviders' => $mailProviders,
        'stageGroups'   => $installStages,
        'text'          => [
            'next'       => __('Continue'),
            'start'      => __('Start setup'),
            'review'     => __('Review settings'),
            'install'    => __('Start installation'),
            'installing' => __('Installing…'),
            'stepOf'     => __('Step :current of :total'),
            'minLeft'    => __('≈ :minutes min left'),
            'almostDone' => __('Almost done'),
            'complete'   => __('Complete'),
            'copied'     => __('Copied'),
            'license'    => [
                'idle'       => __('Not verified yet — required to continue'),
                'verifying'  => __('Verifying…'),
                'contacting' => __('Contacting the update server…'),
                'verified'       => __('License verified and bound to this domain'),
                'verifiedTyped'  => __(':type · bound to :domain'),
                'verifiedBound'  => __('Valid license · bound to :domain'),
                'verifiedButton' => __('Verified'),
                'failed'     => __('The purchase code could not be verified.'),
                'network'    => __('The verification request failed. Check the connection and try again.'),
            ],
            'admin'      => [
                'empty'    => __('Enter a password'),
                'levels'   => [__('Enter a password'), __('Too weak'), __('Fair'), __('Good'), __('Strong')],
                'show'     => __('Show'),
                'hide'     => __('Hide'),
                'weak'     => __('Use at least 8 characters with upper and lower case letters and a number.'),
                'mismatch' => __('The two passwords do not match.'),
            ],
            'email'      => [
                'skip'       => __('Skip for now'),
                'configure'  => __('Configure email instead'),
                'sending'    => __('Sending…'),
                'signingIn'  => __('Signing in to the mail server and sending a test email…'),
                'sent'       => __('Test email sent to :email — check the inbox and the spam folder.'),
                'sentButton' => __('Sent'),
                'failed'     => __('The mail server test failed.'),
                'network'    => __('The test request failed. Check the connection and try again.'),
            ],
            'install'    => [
                'title'          => __('Installing DigiKash…'),
                'lead'           => __('Keep this tab open. Large SQL imports can take a moment on shared hosting.'),
                'sending'        => __('POST /install — sending your settings'),
                'stopped'        => __('Installation stopped'),
                'failedTitle'    => __('Installation stopped'),
                'failedLead'     => __('Nothing after the failed step ran. Fix the cause, then retry.'),
                'failed'         => __('The installer could not finish. Check the log below.'),
                'network'        => __('The installer request failed or timed out. Check the server error log, then retry.'),
                'done'           => __('Installation complete'),
                'doneTitle'      => __('DigiKash is installed'),
                'locked'         => __('Installer locked'),
                'statsTime'      => __('Installed in :seconds s'),
                'statsTables'    => __(':count tables'),
                'statsSeeders'   => __(':count seeders'),
                'securityPrefix' => __('Security:'),
                'envExposed'     => __('.env can be downloaded over HTTP — block it in the web server now; it holds the database password and APP_KEY.'),
                'dumpPresent'    => __('Delete DB/digikash.sql from the server; it is no longer needed.'),
                'envUnknown'     => __('The server could not check itself for a public .env. Open your site address followed by /.env in a browser — it must not load.'),
                'securityOk'     => __('Security checks passed: .env is not served over HTTP and the SQL dump is gone.'),
            ],
            'review'     => [
                'serverOk'      => __('All checks passed'),
                'serverBad'     => __('Checks failing'),
                'verified'      => __('Verified'),
                'notVerified'   => __('Not verified'),
                'connected'     => __('Connected'),
                'notTested'     => __('Not tested'),
                'testSent'      => __('Test email sent'),
                'skipped'       => __('Skipped'),
                'skippedLong'   => __('Skipped — configure later in Admin → Settings → Email'),
                'name'          => __('Name'),
                'url'           => __('URL'),
                'adminLogin'    => __('Admin login'),
                'locale'        => __('Locale'),
                'driver'        => __('Driver'),
                'host'          => __('Host'),
                'database'      => __('Database'),
                'file'          => __('File'),
                'user'          => __('User'),
                'email'         => __('Email'),
                'status'        => __('Status'),
                'provider'      => __('Provider'),
                'from'          => __('From'),
                'import'        => __('Import'),
                'importDump'    => __('DB/digikash.sql · pending migrations · reference data'),
                'importSqlite'  => __('Migrations · core seeders · reference data'),
                'demo'          => __('Demo data'),
                'runtime'       => __('Runtime'),
                'yes'           => __('Yes'),
                'no'            => __('No'),
                'queueDatabase' => __('Database queue'),
                'queueSync'     => __('Sync queue'),
                'cacheOn'       => __('cache on'),
                'cacheOff'      => __('no cache'),
                'pwaOn'         => __('PWA on'),
                'pwaOff'        => __('PWA off'),
            ],
            'database'   => [
                'idle'            => __('Not tested yet — required to continue'),
                'testing'         => __('Testing…'),
                'connecting'      => __('Connecting to the database server…'),
                'preparing'       => __('Preparing the SQLite file…'),
                'connected'       => __('Connected · database is empty and ready'),
                'connectedButton' => __('Connected'),
                'failed'          => __('The database connection failed.'),
                'network'         => __('The test request failed. Check the connection and try again.'),
                'report'          => __('Connection report'),
            ],
            'server'     => [
                'extLoaded'  => __(':loaded / :total loaded'),
                'extMissing' => __(':count missing'),
                'pathsOk'    => __('all writable'),
                'pathsBad'   => __(':count not writable'),
                'blocked'    => __(':count checks failed — installation is blocked'),
                'blockedOne' => __(':count check failed — installation is blocked'),
                'checking'   => __('Checking…'),
            ],
            'notes'      => [
                'welcome'       => __('Nothing is written until you confirm on the review screen'),
                'welcomeTerms'  => __('Accept the license terms to start'),
                'serverOk'      => __('All :count checks passed'),
                'serverBad'     => __('Fix the failed checks to continue'),
                'licenseOk'     => __('License verified and bound to this domain'),
                'licenseBad'    => __('Verify the purchase code to continue'),
                'databaseOk'    => __('Connected — database is empty and ready'),
                'databaseBad'   => __('Test the connection to continue'),
                'emailOk'       => __('Test email sent — mail is ready'),
                'emailSkipped'  => __('Email skipped — you can configure it later'),
                'emailBad'      => __('Send a test email or skip'),
                'review'        => __('Everything verified · ready to install'),
                'application'   => __('Everything here can change later in Admin → Settings'),
                'admin'         => __('Use a password nobody else knows'),
                'options'       => __('Defaults are fine — all of this can change later'),
                'default'       => __('Fill in the fields, then continue'),
            ],
        ],
    ];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Install DigiKash') }}</title>
    <link rel="icon" href="{{ asset('general/static/logo/digikash-mark.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('installer/wizard.css') }}?v={{ config('app.version') }}">
</head>
<body>
<div class="wz" data-wz>
    @include('installer.wizard.sidebar')

    <div class="wz-work">
        @include('installer.wizard.topbar')

        <main class="wz-body">
            <div class="wz-content">
                <form method="POST" action="{{ route('install.store') }}" autocomplete="off" novalidate data-wz-form>
                    @csrf
                    @include('installer.wizard.screens.welcome')
                    @include('installer.wizard.screens.server')
                    @include('installer.wizard.screens.application')
                    @include('installer.wizard.screens.license')
                    @include('installer.wizard.screens.database')
                    @include('installer.wizard.screens.admin')
                    @include('installer.wizard.screens.email')
                    @include('installer.wizard.screens.options')
                    @include('installer.wizard.screens.review')
                    @include('installer.wizard.screens.installing')
                    @include('installer.wizard.screens.success')
                </form>
            </div>
        </main>

        @include('installer.wizard.footer')
    </div>
</div>

<template data-wz-icons>
    @foreach(['home', 'server', 'globe', 'key', 'database', 'user', 'mail', 'sliders', 'clipboard', 'checkCircle', 'check', 'cross', 'alert', 'arrowRight', 'play', 'copy', 'eye', 'eyeOff', 'skip', 'settings'] as $wizardIconName)
        <span data-wz-icon="{{ $wizardIconName }}">@include('installer.wizard.icon', ['name' => $wizardIconName, 'size' => 14])</span>
    @endforeach
</template>

<script>
    window.DK_INSTALLER = @json($wizardClient);
</script>
<script src="{{ asset('installer/wizard.js') }}?v={{ config('app.version') }}"></script>
</body>
</html>
