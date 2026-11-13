<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plugin;
use Illuminate\Database\Seeder;

/**
 * The integration plugins the application reads through pluginCredentials().
 *
 * These rows only ever shipped inside `DB/digikash.sql`, so an installation
 * that never imported the dump (SQLite, or a bare `migrate`) came up with an
 * empty plugin manager — and every feature that checks a plugin's status
 * (Pusher realtime, Twilio SMS, reCAPTCHA, analytics, live exchange rates)
 * silently believed the integration did not exist.
 *
 * Copy, logos and credential shapes mirror the shipped dump exactly, with the
 * same sanitisation the release build applies: every secret value is replaced
 * by its own key name and the plugin ships disabled, so the buyer enables each
 * one after entering their own keys. The nested `fields` blocks survive
 * verbatim because they hold admin-form state, not secrets.
 *
 * Additive on purpose — firstOrCreate, never updateOrCreate. Replaying this on
 * an installation where the administrator has already saved real credentials
 * must not overwrite them, so it is safe in `installer.reconcile_seeders`.
 * The mobile-recharge drivers live in MobileRechargePluginSeeder.
 */
class CorePluginSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->plugins() as $plugin) {
            Plugin::query()->firstOrCreate(
                ['code' => $plugin['code']],
                [
                    'name'        => $plugin['name'],
                    'type'        => $plugin['type'],
                    'logo'        => $plugin['logo'],
                    'description' => $plugin['description'],
                    'credentials' => json_encode($plugin['credentials']),
                    'status'      => false,
                ],
            );
        }
    }

    /**
     * @return list<array{code: string, name: string, type: string, logo: string, description: string, credentials: array<string, mixed>}>
     */
    private function plugins(): array
    {
        return [
            [
                'code'        => 'google-recaptcha',
                'name'        => 'Google reCAPTCHA v2',
                'type'        => 'general',
                'logo'        => 'general/static/plugins/google-recaptcha.svg',
                'description' => 'reCAPTCHA v2 protects your login forms with the "I\'m not a robot" checkbox. Use reCAPTCHA v2 site and secret keys.',
                'credentials' => [
                    'recaptcha_key'    => 'recaptcha_key',
                    'recaptcha_secret' => 'recaptcha_secret',
                ],
            ],
            [
                'code'        => 'fb',
                'name'        => 'Facebook Messenger',
                'type'        => 'general',
                'logo'        => 'general/static/plugins/fb.png',
                'description' => 'Messenger is a proprietary instant messaging app and platform developed by Meta',
                'credentials' => [
                    'page_id' => 'page_id',
                ],
            ],
            [
                'code'        => 'google-analytics',
                'name'        => 'Google Analytics 4',
                'type'        => 'general',
                'logo'        => 'general/static/plugins/google-analytics.png',
                'description' => 'Google Analytics 4 is an analytics service that lets you to measure traffic and engagement across your websites and apps',
                'credentials' => [
                    'ga_measurement_id' => 'ga_measurement_id',
                ],
            ],
            [
                'code'        => 'tawk',
                'name'        => 'Tawk Chat',
                'type'        => 'general',
                'logo'        => 'general/static/plugins/tawk.png',
                'description' => 'Free Instant Messaging system',
                'credentials' => [
                    'property_id' => 'property_id',
                    'widget_id'   => 'widget_id',
                ],
            ],
            [
                'code'        => 'ipinfo',
                'name'        => 'IPinfo.io',
                'type'        => 'general',
                'logo'        => 'general/static/plugins/ipinfo.svg',
                'description' => 'The Trusted Source For IP Address Data',
                'credentials' => [
                    'access_token' => 'access_token',
                ],
            ],
            [
                'code'        => 'currencylayer',
                'name'        => 'Currencylayer',
                'type'        => 'exchange_rate',
                'logo'        => 'general/static/plugins/currencylayer.jpg',
                'description' => 'With over 15 exchange rate data sources, the Exchangerates API is delivering exchanging rates data for more than 170 world currencies.',
                'credentials' => [
                    'api_key' => 'api_key',
                    'fields'  => [
                        'auto_update_time'      => '2',
                        'auto_update_time_unit' => '1',
                        'auto_update_status'    => '0',
                    ],
                ],
            ],
            [
                'code'        => 'coinmarketcap',
                'name'        => 'Coinmarketcap',
                'type'        => 'exchange_rate',
                'logo'        => 'general/static/plugins/coinmarketcap.png',
                'description' => "The world's cryptocurrency data authority has a professional API",
                'credentials' => [
                    'api_key' => 'api_key',
                    'fields'  => [
                        'auto_update_time'      => '1',
                        'auto_update_time_unit' => '1',
                        'auto_update_status'    => '0',
                    ],
                ],
            ],
            [
                'code'        => 'pusher',
                'name'        => 'Pusher',
                'type'        => 'notification',
                'logo'        => 'general/static/plugins/pusher.png',
                'description' => 'Leader In Realtime Technologies.Simple, scalable and reliable.Hosted realtime APIs loved by developers',
                'credentials' => [
                    'pusher_app_id'      => 'pusher_app_id',
                    'pusher_app_key'     => 'pusher_app_key',
                    'pusher_app_secret'  => 'pusher_app_secret',
                    'pusher_app_cluster' => 'pusher_app_cluster',
                ],
            ],
            [
                'code'        => 'twilio',
                'name'        => 'Twilto',
                'type'        => 'notification',
                'logo'        => 'general/static/plugins/twilto.png',
                'description' => 'Twilio is a cloud service that allows sending and receiving SMS through simple, powerful APIs',
                'credentials' => [
                    'account_sid' => 'account_sid',
                    'auth_token'  => 'auth_token',
                    'from'        => 'from',
                ],
            ],
        ];
    }
}
