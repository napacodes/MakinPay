<?php

use Database\Seeders\AddonPermissionSeeder;
use Database\Seeders\AgentCommissionRuleSeeder;
use Database\Seeders\AgentPermissionSeeder;
use Database\Seeders\AuroraThemeComponentSeeder;
use Database\Seeders\BillPaymentPermissionSeeder;
use Database\Seeders\BitnobSeeder;
use Database\Seeders\BitnobStablecoinMethodSeeder;
use Database\Seeders\CoreNotificationTemplateSeeder;
use Database\Seeders\CorePluginSeeder;
use Database\Seeders\CryptoManualMethodSeeder;
use Database\Seeders\CustomLandingSeeder;
use Database\Seeders\DashboardPermissionSeeder;
use Database\Seeders\DashboardSettingSeeder;
use Database\Seeders\DemoBitnobCardholderSeeder;
use Database\Seeders\DemoCardholderSeeder;
use Database\Seeders\DemoUserMerchantSeeder;
use Database\Seeders\EmeraldThemeComponentSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\GiftCardTemplateSeeder;
use Database\Seeders\GoldenThemeComponentSeeder;
use Database\Seeders\KycDocumentHashSeeder;
use Database\Seeders\KycProviderSeeder;
use Database\Seeders\KycSettingSeeder;
use Database\Seeders\LanguageNativeNameSeeder;
use Database\Seeders\MarketplaceSettingSeeder;
use Database\Seeders\MassPaymentPermissionSeeder;
use Database\Seeders\MediaSettingSeeder;
use Database\Seeders\MobileRechargePluginSeeder;
use Database\Seeders\MobileRechargeProviderSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\NotificationTuneSettingSeeder;
use Database\Seeders\ObservabilityPermissionSeeder;
use Database\Seeders\ObservabilitySettingSeeder;
use Database\Seeders\P2PPaymentMethodSeeder;
use Database\Seeders\P2PPromotionPackageSeeder;
use Database\Seeders\PaymentGatewayMethodSeeder;
use Database\Seeders\PaymentGatewaySeeder;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\ReferralContentSeeder;
use Database\Seeders\RemittanceCorridorSeeder;
use Database\Seeders\RewardSeeder;
use Database\Seeders\SecurityNotificationTemplateSeeder;
use Database\Seeders\SiteBuilderSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Database\Seeders\TaxPermissionSeeder;
use Database\Seeders\VirtualCardProviderSeeder;
use Database\Seeders\WalletEarnPlanSeeder;
use Database\Seeders\WithdrawScheduleSeeder;

return [
    /*
     * The legacy web installer's completion lock. Retained so installations
     * created before the CLI installer existed are still recognised as
     * installed. New installs write storage/app/makinpay-installed.json.
     */
    'lock_file' => storage_path('app/installed'),

    'required_extensions' => [
        'BCMath'    => 'bcmath',
        'Ctype'     => 'ctype',
        'DOM'       => 'dom',
        'Fileinfo'  => 'fileinfo',
        'GMP'       => 'gmp',
        'JSON'      => 'json',
        'LibXML'    => 'libxml',
        'Mbstring'  => 'mbstring',
        'OpenSSL'   => 'openssl',
        'PDO'       => 'pdo',
        'PDO MySQL' => 'pdo_mysql',
        'Tokenizer' => 'tokenizer',
        'XML'       => 'xml',
        'ZIP'       => 'zip',
    ],

    'required_functions' => [
        'exec' => 'Required for file and image uploads (media processing). Remove it from disable_functions in php.ini, then restart PHP.',
    ],

    'writable_paths' => [
        'Environment file'  => base_path('.env'),
        'Storage'           => storage_path(),
        'Storage app'       => storage_path('app'),
        'Cache directory'   => storage_path('framework/cache'),
        'Session directory' => storage_path('framework/sessions'),
        'View cache'        => storage_path('framework/views'),
        'Application logs'  => storage_path('logs'),
        'Bootstrap cache'   => base_path('bootstrap/cache'),
        'Public folder'     => public_path(),
    ],

    /*
     * First-install only. Several of these rewrite rows they own on every run
     * (updateOrCreate, channel rebuilds), which is correct against an empty
     * database and destructive against an administrator's edits — replays
     * belong in `reconcile_seeders` below. Order matters: payment gateways
     * before the method seeders that attach to them.
     */
    'core_seeders' => [
        PermissionTableSeeder::class,
        AgentPermissionSeeder::class,
        ObservabilityPermissionSeeder::class,
        ObservabilitySettingSeeder::class,
        DashboardPermissionSeeder::class,
        AddonPermissionSeeder::class,
        BillPaymentPermissionSeeder::class,
        MassPaymentPermissionSeeder::class,
        TaxPermissionSeeder::class,
        TaxPermissionSeeder::class,
        DashboardSettingSeeder::class,
        KycSettingSeeder::class,
        MediaSettingSeeder::class,
        NotificationTuneSettingSeeder::class,
        P2PPaymentMethodSeeder::class,
        P2PPromotionPackageSeeder::class,
        WithdrawScheduleSeeder::class,
        VirtualCardProviderSeeder::class,
        WalletEarnPlanSeeder::class,
        PaymentGatewaySeeder::class,
        FeatureSeeder::class,
        SubscriptionPlanSeeder::class,
        RewardSeeder::class,
        ReferralContentSeeder::class,
        NotificationTemplateSeeder::class,
        BitnobSeeder::class,
        PaymentGatewayMethodSeeder::class,
        CryptoManualMethodSeeder::class,
        BitnobStablecoinMethodSeeder::class,
        KycProviderSeeder::class,
        AgentCommissionRuleSeeder::class,
        GiftCardTemplateSeeder::class,
        RemittanceCorridorSeeder::class,
        MarketplaceSettingSeeder::class,
        CorePluginSeeder::class,
        MobileRechargePluginSeeder::class,
        MobileRechargeProviderSeeder::class,
        SiteBuilderSeeder::class,
        GoldenThemeComponentSeeder::class,
    ],

    /*
     * Replayed after every install AND every update, unattended.
     *
     * Strictly additive seeders only: they create what is missing and never
     * rewrite a row that already exists. `core_seeders` above is first-install
     * only because several of its entries rewrite notification channel copy,
     * which would discard an administrator's edits on every update.
     *
     * FeatureSeeder is the important one for upgrades. A feature key added to
     * `config/feature_catalog.php` in a new version reaches an existing
     * customer's database only through FeatureManager::sync(), and until that
     * row exists FeatureManager::denialReason() reports DENIED_DISABLED and
     * every route guarded by `feature.enabled:<key>` returns 404. sync() sets
     * `is_enabled` and the panel `is_visible`/`is_accessible` flags only when
     * it creates the row, so replaying it never overrides an administrator's
     * on/off decisions.
     *
     * PermissionTableSeeder deliberately stays out of this list: it ends with
     * syncPresetRoles(), and syncPermissions() *replaces* a role's permission
     * set — replaying it would wipe any preset role an administrator had
     * customised.
     */
    'reconcile_seeders' => [
        CoreNotificationTemplateSeeder::class,
        SecurityNotificationTemplateSeeder::class,
        CorePluginSeeder::class,
        FeatureSeeder::class,
        AgentPermissionSeeder::class,
        ObservabilityPermissionSeeder::class,
        ObservabilitySettingSeeder::class,
        DashboardPermissionSeeder::class,
        AddonPermissionSeeder::class,
        BillPaymentPermissionSeeder::class,
        MassPaymentPermissionSeeder::class,
        TaxPermissionSeeder::class,
        DashboardSettingSeeder::class,
        KycSettingSeeder::class,
        KycDocumentHashSeeder::class,
        LanguageNativeNameSeeder::class,
        MediaSettingSeeder::class,
        MarketplaceSettingSeeder::class,
        RemittanceCorridorSeeder::class,
        SiteBuilderSeeder::class,
        CustomLandingSeeder::class,
        EmeraldThemeComponentSeeder::class,
        AuroraThemeComponentSeeder::class,
    ],

    'demo_seeders' => [
        DemoUserMerchantSeeder::class,
        DemoCardholderSeeder::class,
        DemoBitnobCardholderSeeder::class,
    ],

    /*
     * Reference material for operators. The compatibility installer UI was
     * removed in Milestone 1; see MAKINPAY_INSTALLATION.md for the CLI flow.
     */

    /*
     * Presets for the wizard's Email step. They only fill the form: the buyer
     * can still edit every field, and the test email decides. Encryption
     * follows the port the way the SMTP transport does — 465 is implicit
     * SSL, anything else STARTTLS. A null host is filled from the install
     * domain in the browser (mail.<domain>).
     */
    'mail_providers' => [
        'gmail' => [
            'label'      => 'Gmail',
            'host'       => 'smtp.gmail.com',
            'port'       => 587,
            'encryption' => 'tls',
            'hint'       => 'Gmail needs an App Password (Google Account → Security → App passwords), not your normal password.',
        ],
        'outlook' => [
            'label'      => 'Outlook 365',
            'host'       => 'smtp.office365.com',
            'port'       => 587,
            'encryption' => 'tls',
            'hint'       => 'Sign in with the full mailbox address. SMTP AUTH must be enabled for the mailbox in the Microsoft 365 admin centre.',
        ],
        'mailgun' => [
            'label'      => 'Mailgun',
            'host'       => 'smtp.mailgun.org',
            'port'       => 587,
            'encryption' => 'tls',
            'hint'       => 'Use the SMTP credentials from Mailgun → Sending → Domain settings. The From address must be on that verified domain.',
        ],
        'ses' => [
            'label'      => 'Amazon SES',
            'host'       => 'email-smtp.us-east-1.amazonaws.com',
            'port'       => 587,
            'encryption' => 'tls',
            'hint'       => 'Change the region in the host to match your SES region. Sandbox accounts can only send to verified recipients — request production access first.',
        ],
        'sendgrid' => [
            'label'      => 'SendGrid',
            'host'       => 'smtp.sendgrid.net',
            'port'       => 587,
            'encryption' => 'tls',
            'hint'       => 'The username is literally "apikey"; the password is your API key.',
        ],
        'custom' => [
            'label'      => 'Custom / cPanel',
            'host'       => null,
            'port'       => 465,
            'encryption' => 'ssl',
            'hint'       => '',
        ],
    ],
];
