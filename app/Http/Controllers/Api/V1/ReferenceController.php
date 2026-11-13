<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SignInField;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Language;
use App\Models\User;
use App\Services\Auth\OtpLoginService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\HomeCountry;
use App\Support\RealtimeConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the app needs before anyone has signed in.
 *
 * These three are the only unauthenticated endpoints in the mobile API, so
 * the rule for them is narrow: nothing here may depend on who is asking, and
 * nothing here may be a fact about a customer. A sign-in screen needs the
 * site's name and logo, the list of countries its phone field offers, and the
 * currencies it deals in - and nothing else.
 *
 * Every settings read passes a default. An installation upgraded from an
 * older release has no row for a key added later, and a branded sign-in
 * screen that 500s because a logo was never saved is worse than an unbranded
 * one.
 */
class ReferenceController extends Controller
{
    /**
     * The currencies this installation deals in.
     */
    public function currencies(): JsonResponse
    {
        $currencies = Currency::query()
            ->where('status', true)
            ->orderByDesc('default')
            ->orderBy('code')
            ->get();

        return ApiResponse::ok($currencies->map(fn (Currency $currency): array => [
            'code'            => $currency->code,
            'name'            => $currency->name,
            'symbol'          => $currency->symbol,
            'symbol_position' => $currency->symbol_position,
            'type'            => $currency->type,
            'decimals'        => ApiMoney::decimals($currency->code),
            'is_default'      => (bool) $currency->default,
        ])->values());
    }

    /**
     * Whether a username is still free, for the sign-up form to say so as
     * the customer types. Answers the same rule the register request
     * enforces, so the tick and the refusal cannot disagree.
     */
    public function usernameAvailable(Request $request): JsonResponse
    {
        $username   = strtolower(trim((string) $request->query('username', '')));
        $wellFormed = $username !== '' && strlen($username) <= 50 && preg_match('/^[a-z0-9._-]+$/', $username) === 1;

        return ApiResponse::ok([
            'username'  => $username,
            'available' => $wellFormed && ! User::query()->whereRaw('LOWER(username) = ?', [$username])->exists(),
        ]);
    }

    /**
     * Countries and their dial codes, for the phone field on the sign-up form.
     *
     * The same JSON file the web forms read, so the two cannot offer different
     * lists - and the dial code matters, because registration prefixes it.
     */
    public function countries(): JsonResponse
    {
        return ApiResponse::ok(collect(getCountries())->map(fn (array $country): array => [
            'code'      => $country['code']      ?? null,
            'name'      => $country['name']      ?? null,
            'dial_code' => $country['dial_code'] ?? null,
        ])->values());
    }

    /**
     * Branding and the handful of switches the app needs on first launch.
     */
    public function showConfig(): JsonResponse
    {
        $default = Currency::getDefault();

        return ApiResponse::ok([
            'site' => [
                'name'     => setting('site_title', config('app.name')),
                'logo'     => $this->asset(setting('logo', null)),
                'favicon'  => $this->asset(setting('site_favicon', null)),
                'timezone' => setting('site_timezone', config('app.timezone', 'UTC')),

                // The country a number typed without its code belongs to at
                // sign-in, and the one sign-up starts on. Null when the
                // operator chose none.
                'home_country' => HomeCountry::country(),
            ],

            'default_currency' => $default === null ? null : [
                'code'     => $default->code,
                'symbol'   => $default->symbol,
                'decimals' => ApiMoney::decimals($default->code),
            ],

            'locales' => Language::languageGet()->map(fn ($name, $code): array => [
                'code'   => (string) $code,
                'name'   => (string) $name,
                'is_rtl' => Language::directionForLocale((string) $code) === 'rtl',
            ])->values(),

            'registration' => [
                // Read, never assumed: the app should hide a role the site
                // does not offer rather than let someone try and be refused.
                'agent_self_registration' => (bool) setting('agent_self_registration', true),
                'email_verification'      => (bool) setting('email_verification_required', false),
            ],

            // GF9: whether the sign-in screen may offer "text me a code".
            // default_field: which field the sign-in screen opens on, 'email'
            // or 'phone'. Both stay available whatever it says.
            'sign_in' => [
                'sms_code'      => app(OtpLoginService::class)->isAvailable(),
                'default_field' => SignInField::fromSetting()->value,
            ],

            'app' => [
                // Default blocks nobody, so an upgraded installation changes
                // nothing until an administrator sets a floor. The gate that
                // enforces this is its own task.
                'minimum_version' => setting('mobile_minimum_version', null),
            ],

            // Where the app listens for its own notifications as they happen,
            // or null when the site has no realtime and the app refreshes on
            // resume instead. Only the app key is here - it names the app and
            // authorises nothing; the secret signs each subscription in
            // api/v1/broadcasting/auth and never leaves the server. The channel
            // is a template the app fills with its own id, so this answer
            // still says nothing about who is asking.
            'realtime' => RealtimeConnection::client(),
        ]);
    }

    private function asset(?string $path): ?string
    {
        return filled($path) ? asset($path) : null;
    }
}
