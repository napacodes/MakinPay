<?php

namespace App\Facades;

use App\Enums\Observability\ErrorLevel;
use App\Enums\Observability\ErrorSource;
use App\Models\Observability\ErrorEvent;
use App\Services\Observability\ErrorCollector;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * The manual reporting API, aliased as SystemHealth.
 *
 * Application code calls SystemHealth::report($exception, [...]) to record
 * something the automatic capture would not see, or to attach the domain
 * references that turn an exception into a money figure on the error screen.
 *
 * report() never throws. It is safe to call from inside a payment path.
 *
 * @method static void        report(Throwable $throwable, array $context = [], ?ErrorSource $source = null, ?ErrorLevel $level = null)
 * @method static ?ErrorEvent capture(Throwable $throwable, array $context = [], ?ErrorSource $source = null, ?ErrorLevel $level = null)
 * @method static ?ErrorEvent record(array $payload)
 * @method static void        breadcrumb(string $kind, string $label, array $data = [])
 *
 * @see ErrorCollector
 */
class SystemHealthFacade extends Facade
{
    /**
     * Get the registered name of the component in the container.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'system.health'; // Key that matches the service binding
    }
}
