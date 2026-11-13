<?php

namespace App\Services;

use App\Constants\Status;
use App\Models\Language;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class AppConfigService
{
    /**
     * Apply application-wide settings dynamically.
     */
    public function applyAppSettings(): void
    {
        $defaultLanguage     = Language::default();
        $defaultLanguageCode = $defaultLanguage->code ?? 'en';
        Config::set([
            'app.timezone'                                => setting('site_timezone', 'utc'),
            'app.env'                                     => setting('site_environment', 'local'),
            'app.debug'                                   => setting('development_mode', true),
            'app.locale'                                  => $defaultLanguageCode,
            'app.default_language'                        => $defaultLanguageCode,
            'app.default_currency'                        => siteCurrency(),
            'app.default_currency_symbol'                 => siteCurrency('symbol'),
            'security.duplicate_submission_timeout'       => setting('submission_lock_duration', 5),
            'security.secure_response_headers'            => setting('secure_response_headers', true),
            'security.strict_transport_security'          => setting('strict_transport_security', true),
            'security.login_attempt_limit'                => setting('login_attempt_limit', 5),
            'security.login_lock_minutes'                 => setting('login_lock_minutes', 15),
            'security.wallet_pin_attempt_limit'           => setting('wallet_pin_attempt_limit', 5),
            'security.wallet_pin_lock_minutes'            => setting('wallet_pin_lock_minutes', 15),
            'security.merchant_api_signature_required'    => setting('merchant_api_signature_required', true),
            'security.merchant_api_timestamp_tolerance'   => setting('merchant_api_timestamp_tolerance', 300),
            'security.merchant_api_rate_limit_per_minute' => setting('merchant_api_rate_limit_per_minute', 120),
        ]);
    }

    /**
     * Dynamically apply SMTP email settings.
     *
     * Targeted keys only — this used to replace the whole `mail` config
     * array, which silently deleted the log/array/failover mailers and the
     * markdown mail configuration the framework registered from
     * config/mail.php. Admin-panel settings stay the single source of truth
     * for the SMTP transport and the sender identity.
     */
    public function applyMailSettings(): void
    {
        $values = [
            'mail.default'      => 'smtp',
            'mail.from.name'    => $this->mailFromName(),
            'mail.from.address' => setting('email_from_address', 'noreply@example.com'),

            'mail.mailers.smtp.transport' => 'smtp',
            // A MAIL_URL DSN in .env wins over host/port/credentials inside the
            // framework's mailer factory, which would silently override what
            // the admin configured. Admin settings are the source of truth.
            'mail.mailers.smtp.url'        => null,
            'mail.mailers.smtp.host'       => setting('mail_host', 'smtp.example.com'),
            'mail.mailers.smtp.port'       => setting('mail_port', 587),
            'mail.mailers.smtp.username'   => setting('mail_username', 'user@example.com'),
            'mail.mailers.smtp.password'   => setting('mail_password', 'password'),
            'mail.mailers.smtp.encryption' => setting('mail_secure', 'tls'),
        ];

        $changed = false;

        foreach ($values as $key => $value) {
            if (Config::get($key) !== $value) {
                $changed = true;
                break;
            }
        }

        Config::set($values);

        if ($changed) {
            $this->forgetResolvedMailer();
        }
    }

    /**
     * Drop the mailer the container already built, so the next send reads
     * the configuration just applied.
     *
     * `MailManager` resolves the smtp mailer once and keeps it, transport
     * and all. A `queue:work` process that has sent one email therefore
     * carries the SMTP host and credentials it booted with for the rest of
     * its life, and an administrator who corrects Mail Settings afterwards
     * watches every queued email keep failing against the old values.
     * `applyMailSettings()` is re-run before each job precisely so a saved
     * change reaches the worker without a restart, which only works if the
     * stale mailer goes with it.
     */
    private function forgetResolvedMailer(): void
    {
        if (! app()->resolved('mail.manager')) {
            return;
        }

        app('mail.manager')->purge('smtp');
    }

    /**
     * The admin panel exposes an "Email From Name" setting that was never
     * read — every outgoing mail used the site title instead. Honour it now,
     * but treat the vendor placeholder the default settings ship with as
     * unset, so installations that never touched the field keep sending as
     * their own site rather than as the theme author.
     */
    private function mailFromName(): string
    {
        $fromName = trim((string) setting('email_from_name', ''));

        if ($fromName !== '' && strcasecmp($fromName, 'Coevs') !== 0) {
            return $fromName;
        }

        return (string) setting('site_title', 'Wallet System');
    }

    public function applySmsConfig(): void
    {
        $twilioConfig = pluginCredentials('twilio');

        if (! isset($twilioConfig['status']) || $twilioConfig['status'] !== Status::TRUE) {
            return;
        }

        Config::set('twilio-notification-channel', [
            'account_sid' => $twilioConfig['account_sid'],
            'auth_token'  => $twilioConfig['auth_token'],
            'from'        => $twilioConfig['from'],
        ]);
    }

    public function applyGoogleReCaptchaConfig(): void
    {
        $googleReCaptchaCredentials = pluginCredentials('google-recaptcha');

        // An install that never registered the plugin keeps whatever RECAPTCHA_*
        // values .env supplies. Once the row exists it is the single source of
        // truth, so `recaptcha:disable` also switches off stale env values
        // instead of leaving login gated by a widget nobody can satisfy.
        if (! isset($googleReCaptchaCredentials['status'])) {
            return;
        }

        $siteKey = trim((string) ($googleReCaptchaCredentials['recaptcha_key'] ?? ''));
        $secret  = trim((string) ($googleReCaptchaCredentials['recaptcha_secret'] ?? ''));

        // Fail safe toward login access: a blank, placeholder, or malformed key
        // renders a broken widget that issues no token and locks everyone out.
        // Only activate reCAPTCHA when both keys structurally look like real
        // reCAPTCHA keys, regardless of what the stored status says.
        if ($googleReCaptchaCredentials['status'] !== Status::TRUE
            || ! $this->looksLikeRecaptchaKey($siteKey)
            || ! $this->looksLikeRecaptchaKey($secret)) {
            config()->set([
                'services.recaptcha.key'    => null,
                'services.recaptcha.secret' => null,
                'services.recaptcha.status' => false,
            ]);

            return;
        }

        config()->set([
            'services.recaptcha.key'    => $siteKey,
            'services.recaptcha.secret' => $secret,
            'services.recaptcha.status' => $googleReCaptchaCredentials['status'],
        ]);
    }

    private function looksLikeRecaptchaKey(string $value): bool
    {
        return (bool) preg_match('/^6L[0-9A-Za-z_-]{30,}$/', trim($value));
    }

    /**
     * Force HTTPS if enabled.
     */
    public function forceHttpsIfEnabled(): void
    {
        if (config('app.env') !== 'local' && setting('force_https', false)) {
            URL::forceScheme('https');
        }
    }

    /**
     * Ensures the public/storage symlink exists for file uploads.
     * Attempts creation only if missing, logs outcome, never interrupts app.
     */
    public function ensureStorageSymlink(): void
    {
        $link = public_path('storage');

        if (! is_link($link) && ! file_exists($link)) {
            try {
                \Artisan::call('storage:link');
                if (is_link($link) || file_exists($link)) {
                    Log::info('Storage symlink created successfully.');
                } else {
                    Log::warning('Tried to create storage symlink, but it does not exist. Check server permissions.');
                }
            } catch (\Throwable $e) {
                Log::error('Storage symlink creation failed: '.$e->getMessage());
            }
        }
    }
}
