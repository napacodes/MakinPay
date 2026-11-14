<?php

namespace App\Http\Middleware;

use App\Support\InstallationManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse to serve the application until it has been installed.
 *
 * Installation is now a command-line operation (`php artisan makinpay:install`),
 * so this middleware has no web page to forward to. It answers instead with a
 * plain, framework-free 503 telling the operator what to run, which is the only
 * useful thing a visitor can be shown at that point.
 *
 * Nothing about the installation procedure is exposed: the response names the
 * command but never echoes environment values, database names, or credentials,
 * and it does not reveal whether the database is empty, missing, or reachable.
 */
class EnsureApplicationInstalled
{
    public function __construct(private readonly InstallationManager $installer) {}

    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installer->isInstalled()) {
            return $next($request);
        }

        // Machine callers get the same 503, in the shape they expect, so a
        // mobile client or webhook consumer fails cleanly rather than parsing
        // an HTML page.
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => __('The application has not been installed yet.'),
            ], 503);
        }

        return response(
            $this->maintenancePage(),
            503,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Retry-After' => '3600']
        );
    }

    /**
     * A self-contained page: no view, no assets, no layout, so it renders even
     * when the database, the session store, or the compiled views are absent —
     * which is exactly the state this middleware runs in.
     */
    private function maintenancePage(): string
    {
        $heading = e(__('This site is not set up yet'));
        $body    = e(__('The installation has not been completed. A server administrator must run the MakinPay installation command from the project directory.'));
        $command = e('php artisan makinpay:install');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$heading}</title>
<style>
  :root { color-scheme: light dark; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
         font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         background: #f5f6f8; color: #1f2933; padding: 24px; }
  .card { max-width: 34rem; background: #fff; border: 1px solid #e3e8ee; border-radius: 12px;
          padding: 2rem; box-shadow: 0 1px 3px rgba(16,24,40,.06); }
  h1 { margin: 0 0 .75rem; font-size: 1.25rem; }
  p { margin: 0 0 1rem; line-height: 1.6; color: #52606d; }
  code { display: inline-block; background: #f0f3f7; border: 1px solid #e3e8ee; border-radius: 6px;
         padding: .5rem .75rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .9rem; }
  @media (prefers-color-scheme: dark) {
    body { background: #0f1319; color: #e4e7eb; }
    .card { background: #161b22; border-color: #262d36; }
    p { color: #9aa5b1; }
    code { background: #0d1117; border-color: #262d36; }
  }
</style>
</head>
<body>
  <main class="card">
    <h1>{$heading}</h1>
    <p>{$body}</p>
    <code>{$command}</code>
  </main>
</body>
</html>
HTML;
    }
}
