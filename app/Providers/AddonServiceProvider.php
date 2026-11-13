<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Addons\AddonLoader;
use App\Services\Addons\AddonRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Loads installed add-ons from addons/<slug>.
 *
 * Add-on providers are registered here, during the register phase, so they
 * boot alongside core's own providers instead of being appended while the
 * application is already walking its provider list.
 */
class AddonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AddonRegistry::class, fn (): AddonRegistry => new AddonRegistry);
        $this->app->singleton(AddonLoader::class, fn (Application $app): AddonLoader => new AddonLoader($app->make(AddonRegistry::class)));

        $this->app->make(AddonLoader::class)->load($this->app);
    }
}
