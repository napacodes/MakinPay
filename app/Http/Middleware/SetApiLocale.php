<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the reply language for an API request from its `Accept-Language`
 * header.
 *
 * The web panel uses Translate, which reads `session('locale')`. A token client
 * has no session, so without this every reply came back in the site default
 * however the phone was configured. Translate is not reused because it also
 * depends on session state this request does not have.
 *
 * Only a language the admin has switched on is honoured. An unknown or
 * malformed header is not an error - it falls through to the site default, so
 * a phone set to a language the site does not carry still gets a usable reply.
 */
class SetApiLocale
{
    /**
     * Header the client states its preference in.
     */
    public const HEADER = 'Accept-Language';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $available = $this->availableLocales();
        $fallback  = $this->fallbackLocale();

        if ($available === []) {
            return $fallback;
        }

        foreach ($this->requestedLocales($request) as $requested) {
            // An exact match first - `pt-br` should not be answered in `pt`
            // when the site carries both.
            if (isset($available[$requested])) {
                return $available[$requested];
            }

            // Then the primary subtag, so a phone asking for `en-GB` on a site
            // that carries only `en` is still understood.
            $primary = strtok($requested, '-');

            if ($primary !== false && isset($available[$primary])) {
                return $available[$primary];
            }
        }

        return $fallback;
    }

    /**
     * The client's preferences, best first, normalised for comparison.
     *
     * Symfony has already parsed the q-values and returns them in order, using
     * an underscore form (`bn_BD`) that is normalised back to a hyphen here.
     *
     * @return list<string>
     */
    private function requestedLocales(Request $request): array
    {
        if (! $request->hasHeader(self::HEADER)) {
            return [];
        }

        return array_map(
            static fn (string $locale): string => strtolower(str_replace('_', '-', $locale)),
            $request->getLanguages(),
        );
    }

    /**
     * Active site languages, keyed by their normalised code so a lookup does
     * not care how the header was cased.
     *
     * @return array<string, string>
     */
    private function availableLocales(): array
    {
        $locales = [];

        foreach (Language::languageGet()->keys() as $code) {
            $locales[strtolower(str_replace('_', '-', (string) $code))] = (string) $code;
        }

        return $locales;
    }

    private function fallbackLocale(): string
    {
        return Language::default()?->code ?? (string) config('app.default_language', 'en');
    }
}
