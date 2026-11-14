<?php

/*
|--------------------------------------------------------------------------
| Release build configuration
|--------------------------------------------------------------------------
|
| Used by `php artisan release:build` to produce a clean CodeCanyon zip:
|   • everything dev-only (.git, tests, IDE configs, AI tool dirs, etc.)
|     is stripped from the file tree
|   • the SQL dump in the zip contains only system / reference data —
|     no users, admins, transactions, sessions or other tenant data
|
| Edit the lists below when you add new dev tools or new database tables.
*/

return [

    /*
    |----------------------------------------------------------------------
    | Files & folders to EXCLUDE from the release zip
    |----------------------------------------------------------------------
    |
    | Paths are matched against the project-relative path (forward slashes).
    | An entry of "node_modules" matches both top-level "node_modules" and
    | any nested "some/path/node_modules". Glob-style wildcards (e.g. "*.log")
    | are supported via fnmatch().
    */

    'exclude_paths' => [

        // ── Version control & CI ────────────────────────────────────────
        '.git',
        '.gitignore',
        '.gitattributes',
        '.github',
        '.gitlab-ci.yml',

        // ── IDEs & editors ──────────────────────────────────────────────
        '.idea',
        '.vscode',
        '.vs',
        '.fleet',
        '.phpactor.json',
        '.phpactor.yml',
        '.editorconfig',
        '_ide_helper.php',
        '_ide_helper_models.php',
        '.phpstorm.meta.php',

        // ── OS metadata ─────────────────────────────────────────────────
        '.DS_Store',
        'Thumbs.db',
        'desktop.ini',
        '*~',
        '*.bak',
        '*.tmp',
        '*.temp',

        // ── Node / front-end build cache ────────────────────────────────
        'node_modules',
        'npm-debug.log',
        'yarn-error.log',
        'yarn.lock',
        // public/build IS shipped — that's the compiled output buyers need.
        'public/hot',

        // ── Tests & dev tooling ─────────────────────────────────────────
        'tests',
        'phpunit.xml',
        'phpunit.xml.dist',
        '.phpunit.cache',
        '.phpunit.result.cache',
        'pint.json',
        'pest.config.php',

        // ── Composer dev cruft (belt-and-braces — `composer install
        //    --no-dev` removes dev packages; the command also auto-skips
        //    composer.lock packages-dev when --skip-prep is used) ──────
        'vendor/_laravel_idea',
        'vendor/bin',

        // ── Laravel runtime caches ──────────────────────────────────────
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/framework/testing',
        'storage/debugbar',
        'storage/logs',
        'storage/app/public/images/temp',
        'public/storage',
        'bootstrap/cache',
        // Keep bootstrap/cache directory itself with a .gitignore so Laravel
        // doesn't blow up on first boot — handled in the build command.

        // ── Personal / dev artefacts that accumulate in storage/app/ ────
        //  These pile up during local development (plugin caches, install
        //  markers, test receipts) and contain no buyer-relevant data.
        //
        //  IMPORTANT: storage/app/public/files and storage/app/public/images
        //  ARE shipped — the seeded settings (logo, favicon, login banner,
        //  blog images, page-builder content, etc.) reference exact paths
        //  like "images/2026/05/06/foo.png". Excluding them breaks every
        //  default demo asset out of the box. Personal test uploads land
        //  there too, but the trade-off is acceptable: the buyer's first
        //  impression should be a polished demo, not a wall of broken
        //  image icons.
        'storage/app/installed',
        'storage/app/private',
        'storage/app/laravel-brain',
        'storage/app/purifier',
        'storage/app/project-updater-test',
        // Audit CSVs generated from the DEVELOPMENT database by the money
        // audit / reconcile commands. Regenerated on demand; the buyer's
        // install writes its own.
        'storage/app/money-audit',
        // One-off sandbox probe script for the hosted KYC providers.
        'storage/app/probe-hosted-kyc.php',
        'storage/app/updates',
        // Identity documents uploaded while testing KYC under the legacy
        // `local` root. The SQL dump strips every KYC row, so these files
        // belong to nobody in the buyer's install — and they are documents.
        'storage/app/kyc-documents',
        // Probe markers and scratch scripts left by local debugging.
        'storage/app/rollback-probe',
        'storage/app/tmp',
        // Genuinely temporary upload folders (cleared on every run anyway).
        'storage/app/public/images/temp',
        // Locally-generated PDF receipts used during template development.

        // ── Environment & secrets ───────────────────────────────────────
        '.env',
        '.env.backup',
        '.env.local',
        '.env.production',
        '.env.testing',
        'auth.json',

        /*
         * Our own infrastructure, which is nobody's business but ours.
         *
         * deploy.targets.json names three live client servers by host, IP,
         * SSH user and port — including a note about which ports are filtered
         * on one of them, which is a head start for anyone probing it. There
         * are no passwords in the file, and that is not the point: host plus
         * user plus port is most of the way to a targeted attempt, and it
         * would otherwise ship to every buyer.
         */
        'deploy.targets.json',
        '.deploy-state',
        'scripts',

        /*
         * Engineering notes — audits, redesign plans, open questions. Written
         * for us, about work in progress, and of no use to a buyer who wants
         * the README and the changelog.
         */
        'docs',
        'design_handoff_admin_pages',

        // ── AI tooling / dev assistants ─────────────────────────────────
        '.claude',
        '.codex',
        '.codex.json',
        '.ai',
        '.aiassistant',
        '.agents',
        'AGENTS.md',
        'CLAUDE.md',
        'TASKS.md',
        'INBOX.md',

        // ── Internal migration / audit working notes ────────────────────
        // These document an in-progress internal refactor. They contain no
        // runtime code and must never reach a customer download.
        'MAKINPAY_B1_B5_DEFECT_RECORD.md',
        'MAKINPAY_INSTALLATION.md',
        'MAKINPAY_MILESTONE_1_AUDIT.md',
        'MAKINPAY_MILESTONE_1_IMPLEMENTATION.md',
        'MAKINPAY_TESTING.md',

        // ── Test harness ────────────────────────────────────────────────
        // Ships no tests to customers; the release is a production build.
        'tests',
        'phpunit.xml',
        'boost.json',
        'opencode.json',
        '.mcp.json',
        '.cursorrules',
        '.cursorignore',
        '.windsurfrules',

        // ── Local dev environment ───────────────────────────────────────
        'herd.yml',
        'Homestead.yaml',
        'Homestead.json',
        'docker-compose.yml',
        'docker-compose.override.yml',
        'Dockerfile',
        '.docker',
        'laradumps.yaml',

        // ── Backups & local artifacts ───────────────────────────────────
        'core.zip',
        'desktop.zip',
        'releases',
        /*
         * The unzipped source of the WooCommerce plugin. Only the .zip beside
         * it is a deliverable — the buyer's merchants download that from the
         * API docs page (api-docs/sections/woocommerce.blade.php). Nothing
         * links to this folder; it stays in git as the editable source.
         */
        'public/general/woocommerce-gateway-v2.8.0',
        '*.log',
        'database/database.sqlite',
        'database/database.sqlite-journal',
    ],

    /*
    |----------------------------------------------------------------------
    | Folders excluded at the project ROOT only
    |----------------------------------------------------------------------
    |
    | Unlike exclude_paths, these never match a nested folder of the same
    | name. `addons` holds paid add-ons, each sold and signed as its own
    | package (php artisan addon:package); the core zip must not carry them,
    | while a core folder such as resources/views/backend/addons still ships.
    */

    'exclude_root_paths' => [
        'addons',
    ],

    /*
    |----------------------------------------------------------------------
    | Auto-strip rules (FK column heuristic)
    |----------------------------------------------------------------------
    |
    | Before honouring `strip_table_data` below, the builder inspects every
    | table's columns and adds any table containing at least one of these
    | "tenant FK" columns to the strip set automatically. This means new
    | features that follow the usual `user_id` / `merchant_id` convention
    | are stripped without you needing to remember to update this file.
    |
    | The manual list below is added on top — useful for tables that hold
    | tenant data but don't have one of these FK columns (e.g. `users`,
    | `admins`, `sessions`, queue / cache tables).
    |
    | Edge cases (e.g. an admin-configured rules table that happens to
    | carry a `user_id` column) can be exempted from auto-detection via
    | `auto_strip.never_strip` — the manual list still wins.
    */

    'auto_strip' => [

        'enabled' => true,

        'tenant_columns' => [
            'user_id',
            'admin_id',
            'merchant_id',
            'agent_id',
            'cardholder_id',
            'business_id',
            'recipient_user_id',
            'tokenable_id',
            'notifiable_id',
            'model_id',
        ],

        // Tables listed here are exempt from auto-detection — even if they
        // have a tenant FK column, their data will still ship (unless they
        // also appear in the manual strip list below).
        'never_strip' => [
            //
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Database tables to STRIP from the SQL dump (manual list)
    |----------------------------------------------------------------------
    |
    | The schema (CREATE TABLE) for these tables is still emitted so the
    | tables exist after a fresh install — only their rows are skipped.
    | Anything tenant-specific, transactional or session-related belongs
    | here. Reference data (currencies, plans, kyc templates, settings,
    | roles, permissions, etc.) is NOT listed → its rows are included.
    |
    | NOTE: Most tenant tables are already caught by the auto-detection
    | above. Use this list for tables WITHOUT a tenant FK column — e.g.
    | `users`, `admins`, `cache`, `jobs` — or to belt-and-brace specific
    | tables you want explicitly documented as tenant data.
    */

    'strip_table_data' => [

        // Account holders — installer creates the admin on fresh install
        'users',
        'admins',

        // Wallet & finance
        'wallets',
        'transactions',
        'withdraw_accounts',

        // Business profiles
        'merchants',
        'agents',
        'agent_operations',
        'businesses',
        'cardholders',

        // KYC submissions (templates are reference data and stay)
        'kyc_submissions',

        // Virtual cards
        'virtual_cards',
        'virtual_card_requests',

        // Subscriptions (plans are reference data and stay)
        'user_subscriptions',
        'subscription_transactions',
        'merchant_subscriptions',
        'merchant_subscription_invoices',
        'merchant_api_idempotencies',

        // Payment links
        'payment_links',

        // Wallet-earn (plans are reference and stay)
        'wallet_earn_stakes',
        'wallet_earn_rewards',

        // P2P (offers/orders auto-strip via user_id; the pivot and order
        // messages have no tenant FK, so they're listed manually to avoid
        // shipping orphaned rows that point at stripped offers/orders)
        'p2p_offers',
        'p2p_orders',
        'p2p_disputes',
        'p2p_offer_feedback',
        'p2p_offer_promotions',
        'p2p_offer_promotion_purchases',
        'p2p_payment_accounts',
        'p2p_order_messages',
        'p2p_offer_payment_method',

        // Marketplace — vendors auto-strip via user_id; orders carry
        // buyer_id/vendor_id (no recognised tenant FK) so ship without this.
        // `marketplace_settings` intentionally SHIPS (it is the module's
        // config row, and the enable-by-default migration is already recorded
        // in the dump so it never re-runs on the buyer's install). Its
        // `commission_user_id` is nulled via sanitize_table_columns below.
        'marketplace_orders',

        // Remittance — transfers/quotes auto-strip via user_id; their status
        // events key off transfer_id only, so strip them here too.
        'remittance_status_events',

        // Mobile recharge orders (providers are reference and stay)
        'mobile_recharges',

        // Bill payments — payments auto-strip via user_id; their events key
        // off bill_payment_id only, and billers with their items are this
        // site's own setup. Categories and providers are reference data and
        // stay.
        'bill_payment_events',
        'billers',
        'biller_items',

        // Mass payments — batches auto-strip via user_id; their lines and
        // events key off mass_payment_batch_id, so strip them here too.
        'mass_payment_rows',
        'mass_payment_events',

        // Gift cards (templates are reference data and stay)
        'gift_cards',

        // Misc per-user data
        'vouchers',
        'referrals',
        'user_features',
        'tickets',
        'messages',
        'subscribers',

        // Auth tokens & sessions
        'sessions',
        'personal_access_tokens',
        'password_reset_tokens',
        'login_activities',
        'phone_verification_codes',

        // Notifications
        'notifications',
        'notification_preferences',

        // Role assignments (definitions in `roles` and `role_has_permissions`
        // ARE shipped — they're the system role catalogue)
        'model_has_roles',
        'model_has_permissions',

        // Transient / job queue / runtime cache
        'cache',
        'cache_locks',
        'failed_jobs',
        'jobs',
        'job_batches',
        'background_task_logs',
        'project_licenses',
        'project_updates',
    ],

    /*
    |----------------------------------------------------------------------
    | Where the release artefacts land
    |----------------------------------------------------------------------
    */

    'output_dir' => base_path('releases'),

    /*
    |----------------------------------------------------------------------
    | Path INSIDE the zip where the clean SQL dump is written
    |----------------------------------------------------------------------
    |
    | Matches the existing DB/digikash.sql layout that the installer
    | already imports from.
    */

    'sql_dump_path' => 'DB/digikash.sql',

    /*
    |----------------------------------------------------------------------
    | Files to SANITISE on the way into the release zip
    |----------------------------------------------------------------------
    |
    | The file is still shipped, but its content is rewritten before being
    | added to the archive. Keys are project-relative paths (forward slashes,
    | case-insensitive). Values are arrays of `regex pattern => replacement`
    | pairs applied in order.
    |
    | The admin login template no longer needs sanitising: its demo pre-fill is
    | env-driven (config app.demo_admin_email / app.demo_admin_password, both
    | empty by default), so the committed source carries no credentials to
    | strip — this also protects the git-tag update flow, which never runs this
    | sanitiser. Add entries here only if a NEW file ever needs build-time
    | scrubbing.
    */

    'sanitize_files' => [
        //
    ],

    /*
    |----------------------------------------------------------------------
    | Column values to SANITISE in the SQL dump
    |----------------------------------------------------------------------
    |
    | The SQL dump is generated from the DEVELOPMENT database, so any table
    | that ships its rows also ships whatever the developer typed into it.
    | Reference tables such as `payment_gateways`, `plugins` and
    | `kyc_providers` MUST ship (their rows are the provider catalogue the
    | admin UI renders from) but MUST NOT ship live API keys.
    |
    | Two strategies, applied per table while the INSERT rows are built:
    |
    |   'set'  => ['column' => <literal>]
    |       Replace the column value outright. Use null / int / string.
    |
    |   'json' => ['column' => ['keep' => ['a', 'b']]]
    |       The column holds a JSON object whose KEYS drive the admin form.
    |       Every top-level STRING value is replaced with its own key name
    |       (matching the seeder convention, e.g. "api_key" => "api_key"),
    |       so the form still renders every field but carries no secret.
    |       Booleans, numbers and nested arrays are left untouched — that is
    |       what protects flags like `sandbox` and the nested `fields` form
    |       schema. Anything named in `keep` is left untouched as well.
    |
    | Fail-closed by design: a credential key nobody remembered to list is
    | still scrubbed, because `json` scrubs everything except `keep`.
    */

    'sanitize_table_columns' => [

        // KYC provider API keys. `credentials` is an ENCRYPTED blob (not
        // decodable JSON), so it is nulled outright rather than placeholdered.
        // The shipped rows stay useful as country-routing templates; the
        // buyer enables each provider after entering their own keys.
        'kyc_providers' => [
            'set' => [
                'credentials' => null,
                'status'      => 0,
            ],
        ],

        // Plugin credentials — Tawk.to property/widget IDs, reCAPTCHA keys,
        // mobile-recharge driver keys. `credentials.fields` holds the admin
        // form schema as a nested array, so it survives the scrub untouched.
        'plugins' => [
            'json' => [
                'credentials' => ['keep' => []],
            ],
            'set' => [
                'status' => 0,
            ],
        ],

        // Payment gateway API keys for all 25+ providers. `base_url` is a
        // public endpoint, not a secret, and gateways break without it.
        'payment_gateways' => [
            'json' => [
                'credentials' => ['keep' => ['base_url']],
            ],
        ],

        // Object-storage keys for the media library.
        'media_storage_providers' => [
            'set' => [
                'access_key' => null,
                'secret_key' => null,
            ],
        ],

        // Points at the platform commission account, which lives in `users`
        // — a stripped table. Left as-is it would dangle at a row that does
        // not exist on the buyer's install.
        'marketplace_settings' => [
            'set' => [
                'commission_user_id' => null,
            ],
        ],
    ],
];
