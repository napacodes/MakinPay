<?php

return [
    'version' => '2.8.0',

    'product_slug' => env('PROJECT_UPDATER_PRODUCT_SLUG', 'digikash'),
    'item_id'      => env('PROJECT_UPDATER_ENVATO_ITEM_ID', '58275561'),
    'channel'      => env('PROJECT_UPDATER_CHANNEL', 'stable'),

    'server_url' => env('PROJECT_UPDATER_SERVER_URL', 'https://updates.coevs.com'),
    'timeout'    => (int) env('PROJECT_UPDATER_TIMEOUT', 20),
    'retries'    => (int) env('PROJECT_UPDATER_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Add-on licensing (soft)
    |--------------------------------------------------------------------------
    |
    | The core product license that Envato required was REMOVED in Milestone 1.
    | It is no longer checked, activated, or scheduled.
    |
    | Add-on licensing is a SEPARATE mechanism and is retained: a paid add-on
    | declares its own `itemId` and carries its own purchase code, held in
    | `project_licenses` under the add-on's slug. Enforcement stays soft — a
    | server outage never disables a paid add-on immediately:
    |
    |   - Server confirms valid  → the add-on keeps loading.
    |   - Server unreachable     → tolerated for `license_grace_days`.
    |   - Server confirms invalid → the add-on stops loading after grace.
    |
    | Set `addon_license_enforce` to false to disable add-on checks entirely.
    |
    */
    'addon_license_enforce' => (bool) env('PROJECT_UPDATER_ADDON_LICENSE_ENFORCE', true),
    'license_grace_days'    => (int) env('PROJECT_UPDATER_LICENSE_GRACE_DAYS', 14),

    'install_enabled' => (bool) env('PROJECT_UPDATER_INSTALL_ENABLED', true),

    /*
    | Rolling back restores the files ONE install replaced. It never touches
    | the database: the dump in a snapshot predates the install, so replaying
    | it would erase everything written since. Set false on installations
    | where the operator should not be able to revert code from the panel.
    */
    'rollback_enabled' => (bool) env('PROJECT_UPDATER_ROLLBACK_ENABLED', true),
    'public_key'       => env('PROJECT_UPDATER_PUBLIC_KEY') ?: <<<'PUBLIC_KEY'
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAiAVubkVTZdeDI9aIIMSP
7ZtVe6IVoj2748ppHXeLEtJQF8ffn1+hFb56/9Fjk9CQR0R3PvFlPH4+RfRqT8Tp
m24mx6BgtiYh8oX+cCy8zYeyFPt3AR6lLuOtayXwoVJ1UeiveedWDDV4kYiXUpu8
vk/yPpPWl/hZ2pxTGPneTvGz+Jp2Ci+JteMuhXSw4mbDQWQz0XvPoI8AK/Yjihuk
ojI4j3Q2kVnGuia19WKDdmpV56akZK9HFRuGsoeOd/Z8NPK7/P2eKY5H3Q5xCstN
7S8i6W04w+zZ6ZadtXCfB5fcOnjROjuzZSGtsj2RJe37DbcjSSIk2oxRsHCBRhlm
Bv8lOdc7cMDRwlndBfYU/WYa7My6AzDoTW2XITtIe13DYRxJHdkmFqCgCZT5PCMZ
UYJykt/ERoRVbaVpxAa8GOR1YxaJCSmmDukv3RQRI/gre+C3VSPuq+CZNIgPmtMD
Ap04YND9EN7CDccqYLm7ehX6/H5kQPdNOkrYP18kYl/tiL9AC0SQpDftAIoTx9lV
mm1+5VSDXEIbUSADN3bfN+1aMlZ1MeBK8jqyf7o1gwYJsxglTwfOIoAZvxB1xlhe
w5Z+u32Nc+ZR0QS3cdxSUOQuSk3iHnYa75t9SwkiR6lZZOQP2gMWnaIjOkgqeWBr
A4hPkyT44cLR64OkGZZf+jECAwEAAQ==
-----END PUBLIC KEY-----
PUBLIC_KEY,

    'storage_disk'  => env('PROJECT_UPDATER_STORAGE_DISK', 'local'),
    'packages_path' => env('PROJECT_UPDATER_PACKAGES_PATH', 'updates/packages'),
    'extract_path'  => env('PROJECT_UPDATER_EXTRACT_PATH', 'updates/extracted'),
    'backups_path'  => env('PROJECT_UPDATER_BACKUPS_PATH', 'updates/backups'),

    /*
    |--------------------------------------------------------------------------
    | Protected paths — never overwritten during an install
    |--------------------------------------------------------------------------
    |
    | The installer REFUSES to overwrite any file under these paths when
    | applying an update. Protects:
    |   - The buyer's own configuration (.env, storage/, vendor/)
    |   - AI / IDE / dev tool configs the buyer may have customized
    |     (.idea, .vscode, .cursor, .claude, .editorconfig, etc.)
    |   - Local-only files (herd.yml, .htaccess, desktop.ini)
    |   - Lint / format / static-analysis configs
    |
    | This list MUST be kept in sync with the helpdesk's
    | `releases.github.protected_paths` (which excludes the same files at
    | build time). If both layers agree, a buyer's customizations and
    | dev-only files are doubly safe.
    |
    */
    'protected_paths' => [
        // Framework dev files — buyer's own config, never replace
        '.env',
        '.env.example',
        '.env.testing',
        'storage',
        'vendor',
        'node_modules',
        '.git',

        // Apache config — buyer may have customized rewrite rules
        '.htaccess',

        // IDE config directories — buyer's local editor preference
        '.idea',
        '.vscode',
        '.cursor',
        '.windsurf',
        '.zed',

        // AI tool configs — never overwrite buyer's AI setup
        '.ai',
        '.aiassistant',
        '.claude',
        '.codex',
        '.junie',
        '.mcp.json',
        'AGENTS.md',
        'CLAUDE.md',
        'boost.json',
        'opencode.json',

        // IDE helpers — regenerated locally by the buyer
        '_ide_helper.php',
        '_ide_helper_models.php',

        // Local-only configs
        'herd.yml',
        'desktop.ini',

        // Lint / format / editor configs
        '.editorconfig',
        '.gitignore',
        '.gitattributes',
        '.gitmodules',
        '.php-cs-fixer.php',
        '.php-cs-fixer.dist.php',
        'pint.json',
        '.prettierrc',
        '.prettierrc.json',
        '.prettierrc.yml',
        '.prettierignore',
        '.eslintrc',
        '.eslintrc.js',
        '.eslintrc.json',
        '.eslintignore',
        '.stylelintrc',
        '.stylelintrc.json',
        '.styleci.yml',
        '.babelrc',

        // Static analysis configs
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpstan-baseline.neon',
        'psalm.xml',
        'psalm.xml.dist',
        'rector.php',
        '.phpactor.json',

        // Test runner configs (tests directory itself may ship, configs shouldn't)
        'phpunit.xml',
        'phpunit.xml.dist',
        'phpunit.dist.xml',

        // CI / build / containers
        '.github',
        '.gitlab-ci.yml',
        '.circleci',
        '.travis.yml',
        'Makefile',
        'Dockerfile',
        'Dockerfile.dev',
        'docker-compose.yml',
        'docker-compose.dev.yml',
        'docker-compose.override.yml',

        // Docs that conflict with deployed instance's own history
        'CONTRIBUTING.md',
    ],
];
