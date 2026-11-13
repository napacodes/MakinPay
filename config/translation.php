<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Package driver
    |--------------------------------------------------------------------------
    |
    | The package supports different drivers for translation management.
    |
    | Supported: "file", "database"
    |
    */
    'driver' => 'file',

    /*
    |--------------------------------------------------------------------------
    | Route group configuration
    |--------------------------------------------------------------------------
    |
    | The package ships with routes to handle language management. Update the
    | configuration here to configure the routes with your preferred group options.
    |
    */
    'route_group_config' => [
        'middleware' => ['web', 'auth:admin'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation methods
    |--------------------------------------------------------------------------
    |
    | Update this array to tell the package which methods it should look for
    | when finding missing translations.
    |
    */
    'translation_methods' => ['trans', '__'],

    /*
    |--------------------------------------------------------------------------
    | Scan paths
    |--------------------------------------------------------------------------
    |
    | Update this array to tell the package which directories to scan when
    | looking for missing translations.
    |
    */
    'scan_paths' => [app_path(), resource_path()],

    /*
    |--------------------------------------------------------------------------
    | UI URL
    |--------------------------------------------------------------------------
    |
    | Define the URL used to access the language management too.
    |
    */
    'ui_url' => 'languages',

    /*
    |--------------------------------------------------------------------------
    | Database settings
    |--------------------------------------------------------------------------
    |
    | Define the settings for the database driver here.
    |
    | These tables belong to the translation package, not to the application.
    | They are namespaced because the package ships a migration that creates
    | its `languages` table unconditionally, and the application owns a
    | `languages` table of its own (App\Models\Language). Sharing the name
    | made `php artisan migrate` abort on a fresh database with
    | "table languages already exists", which is why only the SQL-dump
    | installer path ever worked.
    |
    | The driver above is `file`, so nothing reads these tables at runtime;
    | the rename only keeps the package's migration out of the way.
    |
    */
    'database' => [

        'connection' => '',

        'languages_table' => 'translation_languages',

        'translations_table' => 'translation_translations',
    ],
];
