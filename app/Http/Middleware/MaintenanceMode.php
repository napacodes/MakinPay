<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceModeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceMode
{
    public function __construct(protected MaintenanceModeService $maintenance) {}

    /**
     * Keep Laravel's maintenance state in step with the site settings.
     *
     * This runs in the `web` group, which is only reached while the site is
     * up or the visitor holds a valid bypass cookie — so it is the safety net
     * that recovers from state changed outside the settings screen, not the
     * primary switch. Saving the settings applies the change directly.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->maintenance->sync();

        return $next($request);
    }
}
