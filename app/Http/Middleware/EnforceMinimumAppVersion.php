<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Api\ApiErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turning away an app version the operator no longer supports.
 *
 * The floor is `mobile_minimum_version`, a layer-C setting whose default is
 * blank - so an upgraded installation changes nothing at all until an
 * administrator sets one. That is the point: a gate that starts switched on
 * locks out every existing customer the moment the update lands.
 *
 * WHAT THIS CANNOT DO, stated plainly because it changes how the floor should
 * be chosen: it can only stop a client that says which version it is. A build
 * that predates the `X-App-Version` header, or any other caller - a merchant's
 * own integration, a curl - sends nothing and is let through. So the floor
 * should be set no lower than the first release that sends the header;
 * setting it lower stops nobody it was meant to stop.
 *
 * An unparseable version is also let through. Refusing it would mean a
 * malformed header locking a customer out of their money over a string
 * comparison, and the version is not a security control - it is a support
 * decision about which builds the operator will answer for.
 */
class EnforceMinimumAppVersion
{
    public const HEADER = 'X-App-Version';

    public const ALIAS = 'api.version';

    /**
     * Routes a blocked app still needs.
     *
     * An app told to upgrade has to be able to say SO - which means reading
     * the config that carries the floor and the store link. Locking that away
     * behind the same gate leaves a customer with a screen that cannot explain
     * itself.
     *
     * @var list<string>
     */
    private const ALWAYS_REACHABLE = [
        'api.mobile.config',
        // The document is how a client learns what the current API is, so a
        // build being turned away is exactly the one that needs to read it.
        'api.mobile.openapi',
        'api.mobile.currencies',
        'api.mobile.countries',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $floor = trim((string) setting('mobile_minimum_version', ''));

        if ($floor === '') {
            return $next($request);
        }

        if (in_array((string) $request->route()?->getName(), self::ALWAYS_REACHABLE, true)) {
            return $next($request);
        }

        $reported = trim((string) $request->header(self::HEADER, ''));

        if ($reported === '' || ! $this->looksLikeAVersion($reported)) {
            return $next($request);
        }

        if (version_compare($reported, $floor, '>=')) {
            return $next($request);
        }

        return ApiResponse::error(
            ApiErrorCode::UpgradeRequired,
            extra: [
                'minimum_version' => $floor,
                'your_version'    => $reported,
            ],
        );
    }

    /**
     * Digits and dots, optionally followed by a pre-release suffix.
     *
     * version_compare() never throws - it will happily compare "banana" with
     * "2.0.0" and reach an answer - so the shape is checked here rather than
     * trusting it to refuse nonsense.
     */
    private function looksLikeAVersion(string $value): bool
    {
        return preg_match('/^\d+(\.\d+)*([.\-+][A-Za-z0-9.\-]+)?$/', $value) === 1;
    }
}
