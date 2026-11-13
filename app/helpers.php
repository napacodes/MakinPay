<?php

use App\Enums\FeeMode;
use App\Enums\MassPayment\MassPayoutApproval;
use App\Enums\SignInField;
use App\Enums\Theme;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Language;
use App\Models\Page;
use App\Models\Plugin;
use App\Models\Setting;
use App\Services\CurrencyService;
use App\Support\CustomerPhone;
use App\Support\HomeCountry;
use App\Support\InstallationManager;
use App\Support\Money;
use App\Support\NotificationTuneLibrary;
use App\Support\ThemePreview;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;

/**
 * Determine if the current route matches a given route(s) and return a CSS class.
 *
 * @param  array|string $route     The route name(s) to check.
 * @param  mixed        $parameter Optional parameter for full URL comparison.
 * @param  string       $class     The CSS class to return if active. Default is 'active'.
 * @return string       The CSS class if the route is active, otherwise an empty string.
 */
if (! function_exists('isActive')) {
    function isActive(array|string $route, mixed $parameter = null, string $class = 'active', array $except_params = []): string
    {
        $currentRouteName = Route::currentRouteName();

        $matchRoute = collect((array) $route)->contains(function (string $routeName) use ($currentRouteName): bool {
            return $currentRouteName === $routeName || Str::is($routeName, (string) $currentRouteName);
        });

        if (! $matchRoute) {
            return '';
        }

        // If no parameter check needed
        if (empty($parameter) || ! is_array($parameter)) {
            return $class;
        }

        // Compare each passed parameter
        foreach ($parameter as $key => $value) {
            if (in_array($key, $except_params)) {
                continue;
            }

            if (request()->query($key) !== $value && request()->route($key) !== $value) {
                return '';
            }
        }

        return $class;
    }
}

/**
 * Convert a given title to a properly formatted and transliterated string.
 *
 * @param  string $title The title to format.
 * @return string The formatted and transliterated title.
 */
if (! function_exists('title')) {
    function title($title): string
    {
        return Str::of($title)
            ->lower()
            ->replace(['_', '-'], ' ')
            ->title()
            ->transliterate();
    }
}

/**
 * Retrieve a setting value from the database.
 *
 * @param  mixed $key     The setting key to fetch. If null, returns a new Setting instance.
 * @param  mixed $default Default value if setting is not found. Default is null.
 * @return mixed The setting value or default value.
 */
if (! function_exists('setting')) {
    function setting($key, $default = null)
    {
        if (is_null($key)) {
            return new Setting;
        }

        try {
            if (! app(InstallationManager::class)->settingsTableAvailable()) {
                return value($default);
            }
        } catch (Throwable) {
            return value($default);
        }

        if (is_array($key)) {
            return Setting::set($key[0], $key[1]);
        }

        try {
            return Setting::get($key, null, $default) ?? value($default);
        } catch (Throwable) {
            return value($default);
        }
    }
}

/**
 * Resolve the account whose notification feed, channel, and tune the current
 * layout owns.
 *
 * Both guards can be authenticated in the same browser (the admin "login as
 * user" flow, or simply an admin panel open in another tab), so the layout
 * passes its own area instead of letting the admin guard always win.
 *
 * @param string|null $area 'admin' or 'user'; guessed from the active guards when omitted.
 */
if (! function_exists('notificationNotifiable')) {
    function notificationNotifiable(?string $area = null): ?Authenticatable
    {
        $area ??= auth('admin')->check() ? 'admin' : 'user';

        return $area === 'admin' ? auth('admin')->user() : auth()->user();
    }
}

/**
 * Resolve the currently-active site theme. Drives both the public
 * Blade layout/section partials and the page-builder component pool.
 *
 * Reads `active_theme` from the settings table (string value) and coerces
 * it to a {@see Theme} case, falling back to Classic when the
 * setting is missing or unrecognised.
 */
if (! function_exists('activeTheme')) {
    function activeTheme(): Theme
    {
        $previewed = ThemePreview::current();

        if ($previewed instanceof Theme) {
            return $previewed;
        }

        try {
            $value = setting('active_theme', Theme::default()->value);
        } catch (Throwable) {
            return Theme::default();
        }

        return Theme::fromValueOrDefault($value);
    }
}

/**
 * Resolve the theme-appropriate Blade view name.
 *
 * When the Golden theme is active, attempts to find a parallel view under a
 * "golden" namespace by inserting "golden" after the second path segment
 * (e.g. `frontend.auth.user.login` → `frontend.auth.golden.user.login`,
 * `frontend.auth.verify-email` → `frontend.auth.golden.verify-email`). Falls
 * back to the original (classic) view if the golden variant doesn't exist —
 * this keeps screens we haven't re-skinned yet rendering normally.
 *
 * Keyed on Golden itself rather than on isDark(): the views it reaches for
 * are Golden's obsidian-and-gold screens, and Aurora is dark too — reading
 * the flag served Aurora visitors a gold sign-in page.
 */
if (! function_exists('themedView')) {
    function themedView(string $view): string
    {
        if (activeTheme() !== Theme::Golden) {
            return $view;
        }

        $segments = explode('.', $view);
        if (count($segments) < 3 || $segments[0] !== 'frontend') {
            return $view;
        }

        $themedSegments = $segments;
        array_splice($themedSegments, 2, 0, ['golden']);
        $themed = implode('.', $themedSegments);

        return view()->exists($themed) ? $themed : $view;
    }
}

/**
 * Retrieve credentials for a given plugin.
 *
 * @param  string $pluginCode The plugin's unique identifier.
 * @return mixed  The credentials associated with the plugin.
 */
if (! function_exists('pluginCredentials')) {
    function pluginCredentials($pluginCode)
    {
        if (app()->environment('testing')) {
            try {
                if (! Schema::hasTable('plugins') || ! Schema::hasColumn('plugins', 'code')) {
                    return [];
                }
            } catch (Throwable) {
                return [];
            }
        }

        try {
            if (! Schema::hasTable('plugins') || ! Schema::hasColumn('plugins', 'code')) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        return Plugin::credentials($pluginCode);
    }
}

/**
 * Flash a notification message to the session.
 *
 * @param string $type    The notification type (e.g., 'success', 'error').
 * @param string $message The message content.
 */
if (! function_exists('notifyEvs')) {
    function notifyEvs($type, $message): void
    {
        session()->flash('notifyevs', ['type' => $type, 'message' => $message]);
    }
}

/**
 * Resolve the text direction ("ltr" or "rtl") for the active locale.
 */
if (! function_exists('language_direction')) {
    function language_direction(): string
    {
        return Language::currentDirection();
    }
}

/**
 * Determine whether the active locale renders right-to-left.
 */
if (! function_exists('is_rtl')) {
    function is_rtl(): bool
    {
        return language_direction() === 'rtl';
    }
}

/**
 * Retrieve selection options for a given type.
 *
 * @param  string $type The type of options to fetch.
 * @return array  The array of available options.
 */
if (! function_exists('setting_select_options')) {
    function setting_select_options(string $type): array
    {

        $formattedTimeZones = collect(getJsonData('time_zone'))->mapWithKeys(function ($item) {
            return ["{$item['utc']} - {$item['name']}" => $item['zone']];
        })->toArray();

        return match ($type) {
            'mail_secure'                 => ['ssl', 'tls'],
            'site_environment'            => ['local', 'production'],
            'site_timezone'               => $formattedTimeZones ?? [],
            'home_country'                => HomeCountry::selectOptions(),
            'home_redirect'               => array_merge(Page::getSlugs(), ['user/login']),
            'site_decimal'                => [0, 1, 2, 3, 4, 5, 6, 7, 8],
            'withdraw_fee_mode'           => FeeMode::options(),
            'exchange_fee_mode'           => FeeMode::options(),
            'mass_payout_approval'        => MassPayoutApproval::options(),
            'bill_payment_token_delivery' => [
                'Receipt only'                 => 'receipt',
                'Receipt, push, email and SMS' => 'notify',
            ],
            'default_sign_in_field'     => SignInField::options(),
            'pwa_display'               => ['standalone', 'fullscreen', 'minimal-ui', 'browser'],
            'pwa_orientation'           => ['portrait-primary', 'landscape-primary', 'portrait', 'landscape', 'any'],
            'notification_tune_default' => collect(NotificationTuneLibrary::tunes())
                ->mapWithKeys(fn (array $tune, string $key): array => [$tune['label'] => $key])
                ->all(),
            default => [],
        };
    }
}

/**
 * Read and decode JSON data from a specified file.
 *
 * @param  string $fileName The name of the JSON file (without extension).
 * @return array  The decoded JSON data as an associative array.
 *
 * @throws Exception If the file is not found or cannot be read.
 */
if (! function_exists('getJsonData')) {
    function getJsonData(string $fileName): array
    {
        $filePath = resource_path("json/{$fileName}.json");

        if (! file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        $jsonContent = file_get_contents($filePath);
        if ($jsonContent === false) {
            throw new Exception("Could not read file: {$filePath}");
        }

        return json_decode($jsonContent, true);
    }
}

/**
 * Retrieve the default site currency.
 *
 * @param  string $type The type of currency data to return (e.g., 'code', 'symbol').
 * @return mixed  The currency data or null if not found.
 */
if (! function_exists('siteCurrency')) {
    function siteCurrency($type = 'code')
    {
        try {
            return app(CurrencyService::class)->getDefaultCurrency()[$type] ?? null;
        } catch (Throwable) {
            return null;
        }
    }
}

/**
 * Retrieve the currency symbol for a given currency code.
 *
 * @param  string      $currencyCode The currency code.
 * @return string|null The corresponding currency symbol or null if not found.
 */
if (! function_exists('getSymbol')) {
    function getSymbol($currencyCode): ?string
    {
        return app(CurrencyService::class)->getCurrencyByCode($currencyCode)['symbol'] ?? null;
    }
}

/**
 * Load and cache the list of countries from a JSON file.
 *
 * @return array The cached country list.
 */
if (! function_exists('getCountries')) {
    function getCountries(): array
    {
        return Cache::rememberForever('countries', function () {
            $path = resource_path('json/country_codes.json');

            return File::exists($path) ? json_decode(File::get($path), true) : [];
        });
    }
}

if (! function_exists('getCountryFlagEmoji')) {
    function getCountryFlagEmoji(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }

        $base = 127397;

        return mb_chr($base + ord($code[0]), 'UTF-8').mb_chr($base + ord($code[1]), 'UTF-8');
    }
}

/**
 * Retrieve country details by country code.
 *
 * @param  string     $code The country code.
 * @return array|null The country details or null if not found.
 */
if (! function_exists('getCountryByCode')) {
    function getCountryByCode($code): ?array
    {
        return collect(getCountries())->firstWhere('code', strtoupper($code));
    }
}

if (! function_exists('getCountryDisplayLabel')) {
    function getCountryDisplayLabel(?string $code, bool $withFlag = true): ?string
    {
        $country = getCountryByCode((string) $code);
        if (! $country) {
            return null;
        }

        $name     = title((string) ($country['name'] ?? ''));
        $dialCode = (string) ($country['dial_code'] ?? '');
        $label    = trim($name.($dialCode !== '' ? ' ('.$dialCode.')' : ''));

        if (! $withFlag) {
            return $label;
        }

        $flag = getCountryFlagEmoji((string) ($country['code'] ?? ''));

        return trim(($flag !== '' ? $flag.' ' : '').$label);
    }
}

/**
 * Retrieve country details by dial code.
 *
 * @param  string     $dialCode The dial code.
 * @return array|null The country details or null if not found.
 */
if (! function_exists('getCountryByDialCode')) {
    function getCountryByDialCode($dialCode): ?array
    {
        return collect(getCountries())->firstWhere('dial_code', $dialCode);
    }
}

/**
 * Retrieve the current location based on the user's IP address.
 *
 * @return array The location data including country code, name, dial code, and IP.
 *
 * @throws Exception If the location cannot be determined, returns an array with null values.
 */
if (! function_exists('getLocation')) {
    function getLocation(): Fluent
    {
        $clientIp = request()->ip();
        $ip       = in_array($clientIp, ['127.0.0.1', '::1']) ? '8.8.8.8' : $clientIp;

        try {
            $response = Http::timeout(5)->get("http://ip-api.com/json/{$ip}");

            if ($response->successful()) {
                $locationData   = $response->json();
                $currentCountry = getCountryByCode($locationData['countryCode'] ?? null);

                return new Fluent([
                    'country_code' => $currentCountry['code']      ?? null,
                    'name'         => $currentCountry['name']      ?? 'Unknown',
                    'dial_code'    => $currentCountry['dial_code'] ?? null,
                    'ip'           => $locationData['query']       ?? $ip,
                ]);
            } else {
                Log::warning('IP API request failed', ['ip' => $ip, 'status' => $response->status()]);
            }
        } catch (Exception $e) {
            Log::error('IP API error', ['ip' => $ip, 'error' => $e->getMessage()]);
        }

        return new Fluent([
            'country_code' => null,
            'name'         => 'Unknown',
            'dial_code'    => null,
            'ip'           => $ip,
        ]);
    }
}

/**
 * Retrieve country name and dial code by country code.
 *
 * @param  string     $code The country code (e.g., 'AF' for Afghanistan)
 * @return array|null Returns an array with 'name' and 'dial_code' if found, otherwise null.
 */
if (! function_exists('getCountryDataByCode')) {
    function getCountryDataByCode($code)
    {
        return getCountryByCode((string) $code);
    }
}

if (! function_exists('registrationLocation')) {
    /**
     * The country a sign-up form starts on.
     *
     * The operator's home country when one is chosen - without asking
     * ip-api.com, which getLocation() does on every render with a five-second
     * timeout - and the visitor's IP country otherwise. A country the visitor
     * picked before a failed submit still wins; the views put old('country')
     * in front of this.
     */
    function registrationLocation(): Fluent
    {
        return HomeCountry::location() ?? getLocation();
    }
}

if (! function_exists('phoneKeepsTrunkZero')) {
    /**
     * Countries whose subscriber number KEEPS its leading zero once the
     * international dial code is in front of it.
     *
     * Almost everywhere the leading zero is a trunk prefix: you dial it to
     * reach another area from inside the country, and you drop it when you
     * dial in from outside. Italy is the well-known exception - +39 06 ... is
     * the correct international form of a Rome landline, zero and all - so
     * stripping it there produces a number that does not ring.
     *
     * A list rather than a library, because the library is a dependency the
     * operator has to install on every server. The list itself lives once,
     * in CustomerPhone::ZERO_IS_PART_OF_THE_NUMBER, so registration, checkout
     * and sign-in cannot disagree about a country (CR3); adding one is one
     * line there.
     *
     * PH1 added Côte d'Ivoire (225), Benin (229) and Congo (242). Rows stored
     * before that, with the zero dropped, are still reached at sign-in through
     * PhoneIdentifier's legacy readings; they are not rewritten.
     */
    function phoneKeepsTrunkZero(string $dialCode): bool
    {
        return CustomerPhone::dialCodeKeepsItsZero($dialCode);
    }
}

if (! function_exists('formatPhone')) {
    /**
     * A typed phone number in international form.
     *
     * THE LEADING ZERO IS DROPPED, which it was not before. A customer types
     * their number the way they would dial it at home - 01712-345678 - and the
     * leading zero is a trunk prefix that means "another area, same country".
     * Prefixing the dial code without removing it produced +88001712345678:
     * one digit too many, in every trunk-prefix country there is, which is
     * most of them. Two things were broken by it. The SMS provider was handed
     * a number that does not exist, so phone verification could not arrive;
     * and nothing a customer could type would ever match what was stored, so
     * signing in by phone was impossible however the query was written.
     *
     * Only ONE zero is removed. A number typed with two is a mistake, and
     * silently repairing a mistake is how a customer ends up verified on
     * somebody else's phone.
     */
    function formatPhone(string $countryCode, string $rawPhone): string
    {
        $dialCode    = preg_replace('/\D+/', '', $countryCode); // remove non-numeric
        $phoneNumber = preg_replace('/\D+/', '', $rawPhone); // clean input
        $typed       = trim($rawPhone);

        /*
         * PH2: A NUMBER THAT ALREADY CARRIES ITS DIAL CODE IS NOT PREFIXED
         * AGAIN. The app sends the picked country's code glued to what was
         * typed ('+880' + '01712345678'), and a customer on the web types
         * '+880 1712-345678' as often as the local form; both used to become
         * +880880..., a number that does not exist.
         *
         * Read as international: anything typed with '+' in front - a
         * foreign number stays foreign - or '00' and this country's code (a
         * doubled zero before anything else is a typing mistake, and only one
         * zero is removed, as below); and, without them, a string that starts
         * with the country's own code and then either its trunk zero, or is
         * too long to be a local number once a code is added (E.164 stops at
         * fifteen digits), or is a North American 1 + ten digits. Italy is
         * left out of the trunk-zero tell: its mobiles begin 3, and 390... is
         * a local number there. Anything else is read as local, exactly as
         * before.
         */
        $international = match (true) {
            str_starts_with($typed, '+')                                      => $phoneNumber,
            $dialCode !== '' && str_starts_with($phoneNumber, '00'.$dialCode) => substr($phoneNumber, 2),
            default                                                           => null,
        };

        // No dial code begins with a zero, so '+0171...' is a local number
        // with a stray '+', read as local exactly as it always was.
        if ($international !== null && ($international === '' || str_starts_with($international, '0'))) {
            $international = null;
        }

        if ($international === null && $dialCode !== '' && str_starts_with($phoneNumber, $dialCode)) {
            $rest = substr($phoneNumber, strlen($dialCode));

            $carriesCode = strlen($dialCode) + strlen($phoneNumber) > 15
                || ($dialCode === '1' && strlen($phoneNumber) === 11)
                || ($dialCode !== '39' && str_starts_with($rest, '0') && strlen($rest) >= 7);

            $international = $carriesCode ? $phoneNumber : null;
        }

        if ($international !== null) {
            // Its own code followed by the trunk zero: the zero goes, as it
            // does for a local typing - unless the country keeps it.
            if ($dialCode !== '' && str_starts_with($international, $dialCode.'0') && ! phoneKeepsTrunkZero($dialCode)) {
                $international = $dialCode.substr($international, strlen($dialCode) + 1);
            }

            return '+'.$international;
        }

        if (! phoneKeepsTrunkZero($dialCode) && str_starts_with($phoneNumber, '0')) {
            $phoneNumber = substr($phoneNumber, 1);
        }

        return '+'.$dialCode.$phoneNumber;
    }
}

/**
 * Retrieve feature statistics for a given transaction type and time period.
 *
 * This function calculates and caches statistics for completed, pending, and failed transactions
 * of a specified type over a given number of days. The results are returned as an array of
 * associative arrays, each containing the title, value, value change, icon, and color class.
 *
 * @param  TrxType $trxType The type of transaction to retrieve statistics for.
 * @param  int     $days    The number of days to calculate statistics over. Default is 7.
 * @return array   An array of statistics for completed, pending, and failed transactions.
 */
if (! function_exists('featureStatistics')) {

    function featureStatistics(TrxType $trxType, $days = 7): array
    {
        $userId   = auth()->user()->id;
        $cacheTtl = now()->addMinutes(5); // Cache lifetime of 5 minutes

        // Cache key for completed stats
        $cacheKeyCompleted = "feature_stats:{$userId}:{$trxType->value}:completed:{$days}";
        $completedStats    = Cache::remember($cacheKeyCompleted, $cacheTtl, function () use ($trxType, $userId, $days) {
            return Transaction::calculateTransactionTypeStatistics(
                $trxType,
                TrxStatus::COMPLETED,
                $userId,
                $days
            );
        });

        // Cache key for pending stats
        $cacheKeyPending = "feature_stats:{$userId}:{$trxType->value}:pending:{$days}";
        $pendingStats    = Cache::remember($cacheKeyPending, $cacheTtl, function () use ($trxType, $userId, $days) {
            return Transaction::calculateTransactionTypeStatistics(
                $trxType,
                TrxStatus::PENDING,
                $userId,
                $days
            );
        });

        // Cache key for failed stats
        $cacheKeyFailed = "feature_stats:{$userId}:{$trxType->value}:failed:{$days}";
        $failedStats    = Cache::remember($cacheKeyFailed, $cacheTtl, function () use ($trxType, $userId, $days) {
            return Transaction::calculateTransactionTypeStatistics(
                $trxType,
                [TrxStatus::FAILED, TrxStatus::CANCELED],
                $userId,
                $days
            );
        });

        return [
            [
                'title'        => __('Completed :type', ['type' => $trxType->label()]),
                'value'        => $completedStats['current_value'],
                'value_change' => $completedStats['current_value'] - $completedStats['previous_value'],
                'icon'         => 'complete',
                'color_class'  => 'success-svg',
            ],
            [
                'title'        => __('Pending :type', ['type' => $trxType->label()]),
                'value'        => $pendingStats['current_value'],
                'value_change' => $pendingStats['current_value'] - $pendingStats['previous_value'],
                'icon'         => 'pending',
                'color_class'  => 'info-svg',
            ],
            [
                'title'        => __('Failed :type', ['type' => $trxType->label()]),
                'value'        => $failedStats['current_value'],
                'value_change' => $failedStats['current_value'] - $failedStats['previous_value'],
                'icon'         => 'failed',
                'color_class'  => 'danger-svg',
            ],
        ];
    }
}

/**
 * Generate user avatar details based on the first and last name.
 *
 * This function extracts the first letter of the first name
 * and the last letter of the last name, converts them to uppercase,
 * and generates a corresponding background class for styling.
 *
 * @param  string $first_name The user's first name.
 * @param  string $last_name  The user's last name.
 * @return array  An associative array containing:
 *                - 'initials' => Combined initials (e.g., "JD" for John Doe).
 *                - 'class' => CSS class for avatar background (e.g., "avatar-bg-j").
 */
if (! function_exists('getUserAvatarDetails')) {
    function getUserAvatarDetails(?string $first_name, ?string $last_name = null): array
    {
        // Handle cases where only a full name is provided in the first parameter
        if ($last_name === null && ! empty($first_name)) {
            $nameParts  = explode(' ', trim($first_name), 2);
            $first_name = $nameParts[0] ?? '';
            $last_name  = $nameParts[1] ?? '';
        }

        // Extract initials
        $firstLetter = ! empty($first_name) ? strtoupper(substr($first_name, 0, 1)) : '';
        $lastLetter  = ! empty($last_name) ? strtoupper(substr($last_name, 0, 1)) : '';

        // Determine avatar background class
        $avatarBgClass = 'avatar-bg-'.strtolower($firstLetter ?: 'default');

        return [
            'initials' => $firstLetter.$lastLetter,
            'class'    => $avatarBgClass,
        ];
    }

}

if (! function_exists('safeUrl')) {
    /**
     * Sanitise an admin-or-user-supplied URL for safe use inside an
     * href attribute. Blocks `javascript:`, `data:`, `vbscript:`, and
     * other unsafe schemes that turn a click into JS execution.
     *
     * Use this whenever the URL came from the database (settings,
     * footer links, notification action_link, merchant site_url, etc.)
     * — anywhere `{{ }}` alone is not enough because the danger is in
     * the scheme, not in HTML entities.
     */
    function safeUrl(?string $url, string $fallback = '#'): string
    {
        if (blank($url)) {
            return $fallback;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return $fallback;
        }

        // Browsers strip ASCII control characters (tab, newline, CR, NUL,
        // etc.) from URLs before parsing the scheme, so an attacker can
        // smuggle a dangerous scheme as `java\tscript:` and have it execute.
        // We must do the same stripping BEFORE the scheme check, otherwise
        // we wave the payload straight through.
        $stripped = preg_replace('/[\x00-\x1F\x7F]+/', '', $trimmed) ?? '';

        if (preg_match('#^(javascript|data|vbscript|file|about|chrome|jar|view-source)\s*:#i', $stripped)) {
            return $fallback;
        }

        // Hand back the cleaned version, not the original — so the browser
        // can never see the smuggled control characters either.
        return $stripped;
    }
}

if (! function_exists('demoProtectedEmails')) {
    /**
     * Canonical list of seeded demo-account emails.
     *
     * These accounts (users, merchants, agents) are the shared sandbox
     * logins published on the CodeCanyon demo install. They must stay
     * usable for every evaluator, so when APP_DEMO=true these emails
     * are forbidden from editing their own email / password / 2FA, etc.
     *
     * Anything else (real accounts the demo owner created for their
     * own testing) is unaffected.
     *
     * @return array<int, string>
     */
    function demoProtectedEmails(): array
    {
        return [
            // Customers
            'ayesha.rahman@digikash.test',
            'imran.hossain@digikash.test',
            // Merchants
            'nusrat.farhana@digikash.test',
            'tanvir.ahmed@digikash.test',
            // Agents
            'farid.uddin@digikash.test',
            'sabila.akter@digikash.test',
        ];
    }
}

if (! function_exists('isDemoProtectedAccount')) {
    /**
     * Should this account be blocked from changing its own
     * credentials right now?
     *
     * Returns true only when BOTH conditions hold:
     *   • the runtime has demo mode enabled (APP_DEMO=true in env);
     *   • the email belongs to the seeded demo-account allowlist.
     *
     * The seller's own admin or any non-demo user is never blocked,
     * even in demo mode — guards using this helper protect only the
     * shared sandbox credentials.
     */
    function isDemoProtectedAccount(?string $email): bool
    {
        if (blank($email)) {
            return false;
        }

        if (! (bool) config('app.demo', false)) {
            return false;
        }

        return in_array(strtolower(trim($email)), demoProtectedEmails(), true);
    }
}

if (! function_exists('highlightWord')) {
    function highlightWord($text, $word = 'money', $class = 'highlight')
    {
        $escapedText = e($text);

        return preg_replace_callback(
            '/\b('.preg_quote($word, '/').')\b/i',
            function ($matches) use ($class) {
                return '<span class="'.e($class).'">'.e($matches[1]).'</span>';
            },
            $escapedText
        );
    }
}

if (! function_exists('__safe')) {
    function __safe($key, $replace = [], $locale = null)
    {
        $translation = __($key, $replace, $locale);

        return is_string($translation) ? $translation : $key;
    }
}

if (! function_exists('getAdminMenuByCode')) {
    function getAdminMenuByCode(string $code)
    {
        $menus = config('admin_menus');

        foreach ($menus as $group) {
            if (! isset($group['menus'])) {
                continue;
            }

            foreach ($group['menus'] as $menu) {
                if (isset($menu['code']) && $menu['code'] === $code) {
                    return $menu;
                }
            }
        }

        return null;
    }
}

/**
 * Format an amount at its currency's own precision and symbol.
 *
 * Pass a currency code whenever one is known — without it the amount falls
 * back to the site base currency, which is wrong for any non-base amount.
 */
if (! function_exists('money')) {
    function money(string|int|float|null $amount, ?string $currencyCode = null, bool $withSymbol = true): string
    {
        return Money::format($amount, $currencyCode, $withSymbol);
    }
}

/**
 * Every active currency's display precision, keyed by code.
 *
 * For screens whose JavaScript has to format more than one currency and cannot
 * carry a `data-decimals` attribute per figure — a fee preview that switches
 * currency with a select, for instance. Reads the cached currency list, so it
 * costs nothing per call.
 *
 * @return array<string, int>
 */
if (! function_exists('currencyDecimalsMap')) {
    function currencyDecimalsMap(): array
    {
        try {
            return app(CurrencyService::class)
                ->getAllCurrencies()
                ->mapWithKeys(fn ($currency): array => [
                    strtoupper((string) $currency->code) => Money::decimals((string) $currency->code),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}

/**
 * Render a configured rate or flat fee without its storage padding.
 *
 * Fee columns are decimal(24,8), so a 2.9% fee reads as "2.90000000" unless it
 * is trimmed. This is for the number itself — the caller supplies the `%` or
 * the currency — and must not be used for an amount, which needs its own
 * currency's precision rather than a trim.
 */
if (! function_exists('feeRate')) {
    function feeRate(string|int|float|null $value): string
    {
        return Money::trimFee($value);
    }
}

/**
 * Display precision for a currency code, falling back to the site default
 * currency and then to the legacy global `site_decimal` setting.
 */
if (! function_exists('currencyDecimals')) {
    function currencyDecimals(?string $currencyCode = null): int
    {
        return Money::decimals($currencyCode);
    }
}

if (! function_exists('formatCurrency')) {
    /**
     * @deprecated Use money() — this hardcodes 2 decimals and always uses the
     *             site base currency's symbol regardless of the amount's own
     *             currency.
     */
    function formatCurrency(float $amount): string
    {
        return Money::format($amount);
    }
}

if (! function_exists('str_replace_placeholders')) {
    function str_replace_placeholders(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{'.$key.'}', $value, $template);
        }

        return $template;
    }
}

if (! function_exists('maskIpAddress')) {
    /**
     * Hide the host part of an address, keeping enough to read the network.
     *
     * A public demo shows real sign-in history, and a full address there is
     * something a visitor can copy and reuse — to probe the network it belongs
     * to, or to impersonate the origin somewhere that trusts it. The first two
     * groups still say roughly where a sign-in came from, which is all the
     * screen is demonstrating; the rest is never the demo's to hand out.
     *
     * Unconditional: callers decide when to mask. `maskSensitive($ip, 'ip')`
     * is the demo-gated way in.
     */
    function maskIpAddress(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = explode('.', $value);

            return $octets[0].'.'.$octets[1].'.***.***';
        }

        if (str_contains($value, ':')) {
            $groups = array_slice(explode(':', $value), 0, 2);

            return implode(':', $groups).':****:****';
        }

        /* Anything else — a hostname, a malformed row — keeps only its head. */
        return mb_substr($value, 0, 4).'****';
    }
}

if (! function_exists('maskSensitive')) {
    function maskSensitive($value, $type = 'email')
    {
        if (config('app.demo')) {
            if ($type === 'email') {
                return preg_replace('/^(.).+(@.+)$/', '$1****$2', $value);
            } elseif ($type === 'phone') {
                return substr($value, 0, 3).'****'.substr($value, -2);
            } elseif ($type === 'ip') {
                return maskIpAddress($value);
            }
        }

        return $value;
    }

}
