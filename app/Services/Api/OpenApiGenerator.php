<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\Api\ApiErrorCode;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/**
 * The OpenAPI document for the mobile API, built FROM THE ROUTING TABLE.
 *
 * Derived rather than written, and that is the whole design. A hand-kept
 * specification is a second description of the system that drifts from the
 * first one the moment somebody adds a route and forgets - and the drift is
 * silent, because nothing fails. This reads Laravel's own route collection, so
 * an endpoint cannot be missing from the document while existing in the
 * application.
 *
 * The middleware stack is read the same way, which is where most of the
 * interesting facts already live: whether a route needs a token, whether it
 * needs an Idempotency-Key, which role it is restricted to, which feature
 * switch gates it, and which rate-limit bucket it shares. None of that is
 * duplicated here; it is reported.
 *
 * WHAT IT DELIBERATELY DOES NOT CLAIM: per-endpoint response bodies. Those
 * would have to be hand-written, and a hand-written response schema that goes
 * stale is worse than no schema at all - a generated client would compile
 * against a shape the server stopped returning. The envelope IS described,
 * exactly, because it is uniform and enforced by ApiResponse; the payload
 * inside it is left open. A Flutter client generated from this gets the
 * transport, the auth, the errors and every path typed, and reads the data
 * object itself.
 */
final class OpenApiGenerator
{
    private const ROUTE_PREFIX = 'api.mobile.';

    public function generate(): array
    {
        return [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => (string) config('app.name').' Mobile API',
                'version'     => 'v1',
                'description' => $this->overview(),
            ],
            'servers' => [
                ['url' => rtrim((string) config('app.url'), '/')],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type'        => 'http',
                        'scheme'      => 'bearer',
                        'description' => 'A personal access token issued by POST /api/v1/auth/login.',
                    ],
                ],
                'schemas' => $this->schemas(),
            ],
            'paths' => $this->paths(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paths(): array
    {
        $paths = [];

        foreach ($this->routes() as $route) {
            $path = '/'.ltrim($this->templatePath($route), '/');

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$path][strtolower($method)] = $this->operation($route);
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $name = (string) $route->getName();

            if ($name !== '' && str_starts_with($name, self::ROUTE_PREFIX)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * One operation, with every fact taken from the route rather than restated.
     *
     * @return array<string, mixed>
     */
    private function operation(Route $route): array
    {
        $name       = (string) $route->getName();
        $middleware = $this->resolvedMiddleware($route);

        $operation = [
            'operationId' => Str::camel(str_replace([self::ROUTE_PREFIX, '.', '-'], ['', ' ', ' '], $name)),
            'tags'        => [$this->tag($name)],
            'summary'     => $this->summary($name),
            'parameters'  => $this->parameters($route, $middleware),
            'responses'   => $this->responses($middleware),
        ];

        if ($this->needsToken($middleware)) {
            $operation['security'] = [['bearerAuth' => []]];
        } else {
            // Explicitly empty rather than absent: absent inherits the
            // document-level default, and saying "no auth" out loud is the
            // point for a sign-in or a password reset.
            $operation['security'] = [];
        }

        if (in_array('POST', $route->methods(), true)
            || in_array('PUT', $route->methods(), true)
            || in_array('PATCH', $route->methods(), true)) {
            $operation['requestBody'] = [
                'required' => false,
                'content'  => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ];
        }

        $operation['x-digikash'] = array_filter([
            'role'        => $this->argumentFor($middleware, 'EnsureApiRole'),
            'feature'     => $this->argumentFor($middleware, 'EnsureFeatureEnabled'),
            'userFeature' => $this->argumentFor($middleware, 'CheckUserFeature'),
            'rateLimit'   => $this->argumentFor($middleware, 'ThrottleRequests'),
            'idempotent'  => $this->hasMiddleware($middleware, 'EnsureApiIdempotency') ? true : null,
        ], static fn ($value): bool => $value !== null);

        return $operation;
    }

    /**
     * Headers and path parameters.
     *
     * Idempotency-Key is marked required exactly where the middleware demands
     * it, so a generated client asks for one on the calls that move money and
     * nowhere else.
     *
     * @param  list<string>               $middleware
     * @return list<array<string, mixed>>
     */
    private function parameters(Route $route, array $middleware): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[] = [
                'name'     => $parameter,
                'in'       => 'path',
                'required' => true,
                'schema'   => ['type' => 'string'],
            ];
        }

        if ($this->hasMiddleware($middleware, 'EnsureApiIdempotency')) {
            $parameters[] = [
                'name'        => 'Idempotency-Key',
                'in'          => 'header',
                'required'    => true,
                'schema'      => ['type' => 'string', 'maxLength' => 255],
                'description' => 'A value the client chooses per attempt. Sending the same key with the same body replays the first answer instead of acting twice; the same key with a different body is a 409.',
            ];
        }

        if ($this->hasMiddleware($middleware, 'EnforceMinimumAppVersion')) {
            $parameters[] = [
                'name'        => 'X-App-Version',
                'in'          => 'header',
                'required'    => false,
                'schema'      => ['type' => 'string'],
                'description' => 'The client build. Below the administrator floor the answer is 426 UPGRADE_REQUIRED. Omitted means unknown, which is allowed.',
            ];
        }

        if ($this->hasMiddleware($middleware, 'SetApiLocale')) {
            $parameters[] = [
                'name'        => 'Accept-Language',
                'in'          => 'header',
                'required'    => false,
                'schema'      => ['type' => 'string'],
                'description' => 'Reply language, matched against the languages the installation has enabled.',
            ];
        }

        return $parameters;
    }

    /**
     * The answers every operation can give.
     *
     * The envelope is described exactly, because ApiResponse enforces it; the
     * data object inside is left open, because inventing a shape per endpoint
     * is how a specification starts lying.
     *
     * @param  list<string>         $middleware
     * @return array<string, mixed>
     */
    private function responses(array $middleware): array
    {
        $responses = [
            '200' => $this->success(),
            '422' => $this->error('The request was refused. `code` says why.'),
            '429' => $this->error('Rate limited. Retry-After says for how long.'),
        ];

        if ($this->needsToken($middleware)) {
            $responses['401'] = $this->error('No token, or one that is no longer valid.');
            $responses['403'] = $this->error('Signed in, and not allowed this.');
            $responses['404'] = $this->error('Not found, or a module this installation does not offer.');
        }

        if ($this->hasMiddleware($middleware, 'EnsureApiIdempotency')) {
            $responses['409'] = $this->error('The same Idempotency-Key was used for a different request.');
        }

        if ($this->hasMiddleware($middleware, 'EnforceMinimumAppVersion')) {
            $responses['426'] = $this->error('This build is below the administrator floor.');
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function success(): array
    {
        return [
            'description' => 'Success.',
            'content'     => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Success'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $description): array
    {
        return [
            'description' => $description,
            'content'     => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Error'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        return [
            'Success' => [
                'type'       => 'object',
                'required'   => ['status'],
                'properties' => [
                    'status'  => ['type' => 'boolean', 'const' => true],
                    'message' => ['type' => 'string', 'nullable' => true],
                    'data'    => ['description' => 'The payload, whose shape depends on the endpoint.'],
                ],
            ],
            'Error' => [
                'type'       => 'object',
                'required'   => ['status', 'code', 'message'],
                'properties' => [
                    'status' => ['type' => 'boolean', 'const' => false],
                    'code'   => [
                        'type'        => 'string',
                        'enum'        => array_map(static fn (ApiErrorCode $case): string => $case->value, ApiErrorCode::cases()),
                        'description' => 'The stable reason. Branch on this rather than on the message, which is translated.',
                    ],
                    'message' => ['type' => 'string'],
                    'errors'  => [
                        'type'                 => 'object',
                        'nullable'             => true,
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'description'          => 'Field errors, when the refusal was about the fields.',
                    ],
                ],
            ],
        ];
    }

    /**
     * `api/v1/wallets/{wallet}` from Laravel's own `{wallet}` syntax, which is
     * already the OpenAPI one - so this only normalises optional parameters.
     */
    private function templatePath(Route $route): string
    {
        return preg_replace('/\{(\w+)\?\}/', '{$1}', $route->uri()) ?? $route->uri();
    }

    /**
     * The route's middleware with aliases resolved to class names.
     *
     * `gatherMiddleware()` reports what the route WROTE - `api.idempotency`,
     * `feature.enabled:withdraw_money` - while the router holds the map to
     * what those mean. Matching on the written form alone would break the
     * moment an alias were renamed, and matching on the class alone misses
     * every route that used the alias, which is all of them. Both are kept,
     * so either spelling matches.
     *
     * @return list<string>
     */
    private function resolvedMiddleware(Route $route): array
    {
        $aliases  = RouteFacade::getMiddleware();
        $resolved = [];

        foreach ($route->gatherMiddleware() as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $resolved[] = $entry;

            $name      = Str::before($entry, ':');
            $arguments = Str::contains($entry, ':') ? ':'.Str::after($entry, ':') : '';

            if (isset($aliases[$name])) {
                $resolved[] = $aliases[$name].$arguments;
            }
        }

        return $resolved;
    }

    private function tag(string $name): string
    {
        $rest = Str::after($name, self::ROUTE_PREFIX);

        return str_contains($rest, '.') ? Str::before($rest, '.') : 'general';
    }

    /**
     * A readable line from the route name, which is the only description that
     * cannot go stale: it is regenerated from the route every time.
     */
    private function summary(string $name): string
    {
        return Str::ucfirst(str_replace(['.', '-', '_'], ' ', Str::after($name, self::ROUTE_PREFIX)));
    }

    /**
     * @param list<string> $middleware
     */
    private function needsToken(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if (str_contains($entry, 'AuthenticateMiddleware') && str_contains($entry, 'sanctum')) {
                return true;
            }

            if ($entry === 'auth:sanctum') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $middleware
     */
    private function hasMiddleware(array $middleware, string $needle): bool
    {
        foreach ($middleware as $entry) {
            if (str_contains($entry, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The argument a parameterised middleware was given - the feature key, the
     * role, the rate-limit bucket.
     *
     * @param list<string> $middleware
     */
    private function argumentFor(array $middleware, string $needle): ?string
    {
        foreach ($middleware as $entry) {
            if (! str_contains($entry, $needle)) {
                continue;
            }

            $argument = Str::after($entry, ':');

            return $argument === $entry ? null : $argument;
        }

        return null;
    }

    private function overview(): string
    {
        return <<<'TEXT'
        Generated from the application's own routing table, so an endpoint cannot exist without appearing here.

        Every reply is the same envelope. A success carries `status: true` and a `data` object; a refusal carries `status: false`, a stable `code` to branch on, and a translated `message` to show. Branch on the code - the message changes with the customer's language.

        Response payloads inside `data` are intentionally not described per endpoint. A hand-written response schema drifts from the server silently, and a client generated against a stale one compiles and then breaks. What is described is everything that is enforced in code and therefore cannot drift: the paths, the methods, the authentication, the headers, and the error shape.

        `x-digikash` on each operation reports the guards the route actually carries - the role, the feature switch, the rate-limit bucket, and whether an Idempotency-Key is required. These are read from the middleware stack, not restated.
        TEXT;
    }
}
