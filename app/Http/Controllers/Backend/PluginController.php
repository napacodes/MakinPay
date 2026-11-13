<?php

namespace App\Http\Controllers\Backend;

use App\Constants\Status;
use App\Contracts\BillPayment\BillPaymentProviderInterface;
use App\Contracts\BillPayment\ProvidesBillPaymentDiagnostics;
use App\Models\BillPaymentProvider;
use App\Models\MobileRechargeProvider;
use App\Models\Plugin;
use App\Services\MobileRecharge\Drivers\ReloadlyMobileRechargeProvider;
use App\Support\PluginSecretFields;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Throwable;

class PluginController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'index|pluginType|edit|update|test|toggleStatus' => 'plugins-manage',
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return $this->getPluginsView();
    }

    public function pluginType(string $plugin_type): View
    {
        return $this->getPluginsView($plugin_type);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $plugin = Plugin::findOrFail($id);

        // Secrets never reach the form. The view only learns whether one is
        // saved, so the stored keys cannot be read from the page source or
        // copied off a demo install.
        $storedCredentials = $plugin->credentialsArray();
        $savedSecrets      = [];
        $formCredentials   = [];

        foreach ($storedCredentials as $key => $value) {
            if (is_string($key) && $key !== 'fields' && PluginSecretFields::isSecret($key)) {
                $savedSecrets[$key]    = PluginSecretFields::hasStoredValue($key, $value);
                $formCredentials[$key] = '';

                continue;
            }

            $formCredentials[$key] = $value;
        }

        $plugin->credentials = $formCredentials;

        return view('backend.settings.plugin.partials.__manage_append', compact('plugin', 'savedSecrets'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        // Validate the request data
        $validatedData = $request->validate([
            'credentials' => 'required|array',
            'fields'      => 'nullable|array',
        ]);

        // Handle optional fields and encode them to JSON if present
        if (isset($validatedData['fields'])) {
            $validatedData['credentials']['fields'] = $validatedData['fields'];
        }

        $plugin        = Plugin::findOrFail($id);
        $credentials   = PluginSecretFields::withKeptSecrets($validatedData['credentials'], $plugin->credentialsArray());
        $requestStatus = (int) $request->input('status', Status::FALSE);

        $guard            = $this->guardRecaptchaActivation($plugin, $credentials, $requestStatus);
        $requestStatus    = $guard['status'];
        $recaptchaWarning = $guard['warning'];
        $recaptchaCaveat  = $guard['caveat'];

        $plugin->update([
            'credentials' => json_encode(PluginSecretFields::seal($credentials)),
            'status'      => $requestStatus,
        ]);

        if ($plugin->type == 'notification') {
            Artisan::call('config:cache');
        }

        if ($recaptchaWarning !== null) {
            notifyEvs('warning', __('reCAPTCHA was saved but left disabled to protect login access. :reason', ['reason' => $recaptchaWarning]));
        } elseif ($recaptchaCaveat !== null) {
            notifyEvs('success', __('reCAPTCHA is now active. :reason', ['reason' => $recaptchaCaveat]));
        } else {
            notifyEvs('success', __('Info Updated Successfully'));
        }

        return redirect()->back();
    }

    /**
     * A broken reCAPTCHA widget issues no token, which locks every admin and
     * user out of login. Never let it go live with a wrong key type, a
     * malformed key, or a secret Google rejects: keep it disabled instead.
     *
     * Extracted from update() so the Integration Center's card switch runs the
     * same check. A control that turns a plugin on without it would be a way
     * around the lockout guard that happens to be one click shorter.
     *
     * @param  array<string, mixed>                                          $credentials
     * @return array{status: int, warning: string|null, caveat: string|null}
     */
    private function guardRecaptchaActivation(Plugin $plugin, array $credentials, int $requestStatus): array
    {
        if ($plugin->code !== 'google-recaptcha' || $requestStatus !== Status::TRUE) {
            return ['status' => $requestStatus, 'warning' => null, 'caveat' => null];
        }

        $validation = $this->recaptchaValidationState(
            (string) ($credentials['recaptcha_key'] ?? ''),
            (string) ($credentials['recaptcha_secret'] ?? ''),
        );

        if ($validation['state'] === 'invalid') {
            return ['status' => Status::FALSE, 'warning' => $validation['reason'], 'caveat' => null];
        }

        if ($validation['state'] === 'inconclusive' && $this->recaptchaIsChanging($plugin, $credentials)) {
            /*
             * Worth saying once, when the keys go live or change. Repeating it
             * on every save of an already-working widget reads as a failure
             * and trains admins to ignore the real warning above.
             */
            return ['status' => $requestStatus, 'warning' => null, 'caveat' => $validation['reason']];
        }

        return ['status' => $requestStatus, 'warning' => null, 'caveat' => null];
    }

    /**
     * The Integration Center's card switch: on or off without opening the
     * dialog.
     *
     * Credentials are never read from the request here — only the stored ones
     * are consulted, and only to decide whether activation is safe. So this
     * endpoint can turn a provider on and off, and cannot be used to write a
     * key.
     */
    public function toggleStatus(Plugin $plugin, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'boolean'],
        ]);

        $requested = (bool) $validated['status'] ? Status::TRUE : Status::FALSE;
        $guard     = $this->guardRecaptchaActivation($plugin, $plugin->credentialsArray(), $requested);

        $plugin->update(['status' => $guard['status']]);

        if ($plugin->type === 'notification') {
            Artisan::call('config:cache');
        }

        $plugin->refresh();

        return response()->json([
            'status'  => 'success',
            'enabled' => (bool) $plugin->status,
            'state'   => $plugin->state,
            'message' => $guard['warning'] ?? ($plugin->status
                ? __(':plugin is now active.', ['plugin' => $plugin->name])
                : __(':plugin is now switched off.', ['plugin' => $plugin->name])),
            'blocked' => $guard['warning'] !== null,
        ]);
    }

    public function test(Plugin $plugin, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credentials' => ['required', 'array'],
            'fields'      => ['nullable', 'array'],
        ]);

        $credentials = PluginSecretFields::withKeptSecrets($validated['credentials'], $plugin->credentialsArray());

        if (isset($validated['fields'])) {
            $credentials['fields'] = $validated['fields'];
        }

        $missingKeys = $this->missingCredentialKeys($credentials, $plugin->optionalCredentialKeys());

        if ($missingKeys !== []) {
            return response()->json([
                'status'  => 'error',
                'message' => __('Missing credentials: :fields', ['fields' => implode(', ', $missingKeys)]),
            ], 422);
        }

        try {
            $result = $this->testPluginCredentials($plugin, $credentials);

            /*
             * Stamped on anything that is not an outright failure — a
             * "warning" here means the provider answered but could not be
             * proved, which is still a check somebody ran. The card reads this
             * as "Tested 2h ago" against "Not tested", so recording only
             * perfect passes would leave every provider without an automated
             * check looking as though nobody had ever looked at it.
             */
            if (($result['status'] ?? null) !== 'error') {
                $plugin->forceFill(['last_tested_at' => now()])->save();
            }

            return response()->json($result);
        } catch (Throwable $exception) {
            return response()->json([
                'status'  => 'error',
                'message' => __('Connection test failed: :message', ['message' => $exception->getMessage()]),
            ], 422);
        }
    }

    private function getPluginsView(?string $selectedPluginType = null): View
    {
        $plugins = Plugin::query()
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return view('backend.settings.plugin.index', compact('plugins', 'selectedPluginType'));
    }

    /**
     * @param  array<string, mixed> $credentials
     * @param  list<string>         $optional    keys the plugin's driver works without
     * @return list<string>
     */
    private function missingCredentialKeys(array $credentials, array $optional = []): array
    {
        $missing = [];

        foreach ($credentials as $key => $value) {
            if ($key === 'fields' || $value === '0' || $value === '1' || is_bool($value) || in_array($key, $optional, true)) {
                continue;
            }

            if ($this->isBlankCredential($value)) {
                $missing[] = ucwords(str_replace('_', ' ', (string) $key));
            }
        }

        return $missing;
    }

    private function isBlankCredential(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return true;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized === ''
            || in_array($normalized, [
                'api_key',
                'access_token',
                'account_sid',
                'auth_token',
                'page_id',
                'property_id',
                'widget_id',
                'recaptcha_key',
                'recaptcha_secret',
                'pusher_app_id',
                'pusher_app_key',
                'pusher_app_secret',
                'pusher_app_cluster',
            ], true);
    }

    private function testPluginCredentials(Plugin $plugin, array $credentials): array
    {
        if ($plugin->type === Plugin::TYPE_MOBILE_RECHARGE && ($plugin->rechargeProvider?->driver ?? $plugin->code) === 'reloadly') {
            return $this->testReloadlyCredentials($plugin, $credentials);
        }

        if ($plugin->type === Plugin::TYPE_BILL_PAYMENT && $plugin->billPaymentProvider !== null) {
            return $this->testBillPaymentCredentials($plugin->billPaymentProvider, $credentials);
        }

        return match ($plugin->code) {
            'google-recaptcha' => $this->testRecaptchaCredentials($credentials),
            'ipinfo'           => $this->testIpinfoCredentials($credentials),
            'currencylayer'    => $this->testCurrencylayerCredentials($credentials),
            'coinmarketcap'    => $this->testCoinmarketcapCredentials($credentials),
            'pusher'           => $this->testPusherCredentials($credentials),
            'twilio'           => $this->testTwilioCredentials($credentials),
            default            => [
                'status'  => 'warning',
                'message' => __('Automated API check is not available for :plugin yet. Required credential fields are filled.', [
                    'plugin' => $plugin->name,
                ]),
            ],
        };
    }

    /**
     * The real Reloadly probe, run against the keys in the dialog.
     *
     * This button used to answer "automated check is not available" for
     * Reloadly while a full probe already existed on the provider card, so the
     * one place an admin pastes the keys could not say whether they worked.
     *
     * @param  array<string, mixed>                   $credentials
     * @return array{status: string, message: string}
     */
    private function testReloadlyCredentials(Plugin $plugin, array $credentials): array
    {
        $provider = $plugin->rechargeProvider ?? new MobileRechargeProvider(['code' => $plugin->code, 'driver' => 'reloadly']);
        $driver   = new ReloadlyMobileRechargeProvider;
        $driver->configure($provider, $credentials, is_array($provider->config) ? $provider->config : []);

        $result = $driver->testConnection();
        $reason = data_get($result, 'details.provider_error');

        return [
            'status'  => $result['ok'] ? 'success' : 'error',
            'message' => is_string($reason) && $reason !== ''
                ? $result['message'].' '.__('Reloadly said: :reason', ['reason' => $reason])
                : $result['message'],
        ];
    }

    /**
     * A bill payment provider's own probe, run against the keys in the
     * dialog rather than the saved ones, so an administrator can check a key
     * before saving it. The driver is built the way the provider manager
     * builds it, with these credentials in place of the stored ones.
     *
     * @param  array<string, mixed>                   $credentials
     * @return array{status: string, message: string}
     */
    private function testBillPaymentCredentials(BillPaymentProvider $provider, array $credentials): array
    {
        $class  = ((array) config('bill_payments.drivers', []))[(string) $provider->driver] ?? null;
        $driver = is_string($class) && class_exists($class) ? app($class) : null;

        if (! $driver instanceof BillPaymentProviderInterface || ! $driver instanceof ProvidesBillPaymentDiagnostics) {
            return [
                'status'  => 'warning',
                'message' => __('Automated API check is not available for :plugin yet. Required credential fields are filled.', [
                    'plugin' => $provider->name,
                ]),
            ];
        }

        $driver->configure($provider, $credentials, is_array($provider->config) ? $provider->config : []);

        $result = $driver->testConnection();

        return [
            'status'  => ($result['ok'] ?? false) === true ? 'success' : 'error',
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    private function testRecaptchaCredentials(array $credentials): array
    {
        $validation = $this->recaptchaValidationState(
            (string) ($credentials['recaptcha_key'] ?? ''),
            (string) ($credentials['recaptcha_secret'] ?? ''),
        );

        return match ($validation['state']) {
            'valid'   => ['status' => 'success', 'message' => __('Google reCAPTCHA v2 keys verified. The site key is registered with Google and the secret was accepted.')],
            'invalid' => ['status' => 'error', 'message' => $validation['reason']],
            default   => ['status' => 'warning', 'message' => $validation['reason']],
        };
    }

    /**
     * Decide whether a pair of reCAPTCHA v2 keys is safe to activate.
     *
     * `invalid` keys must never go live (they break the login widget); `valid`
     * keys are confirmed by Google; `inconclusive` means we could not prove the
     * pair either way, so the admin is warned rather than hard-blocked.
     *
     * Only two signals are authoritative: the key format, and Google's verdict
     * on the secret. The site-key probe below is advisory on purpose.
     *
     * @return array{state: 'valid'|'invalid'|'inconclusive', reason: string}
     */
    private function recaptchaValidationState(string $siteKey, string $secret): array
    {
        $siteKey = trim($siteKey);
        $secret  = trim($secret);

        if (! $this->looksLikeRecaptchaKey($siteKey) || ! $this->looksLikeRecaptchaKey($secret)) {
            return [
                'state'  => 'invalid',
                'reason' => __('These do not look like reCAPTCHA v2 keys. Both values should be the ~40 character keys that start with "6L".'),
            ];
        }

        try {
            $secretResponse = Http::asForm()
                ->timeout(15)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret'   => $secret,
                    'response' => 'digikash-credential-check',
                ]);
        } catch (Throwable) {
            return ['state' => 'inconclusive', 'reason' => __('Could not reach Google to verify the secret. Try again in a moment.')];
        }

        $errors = (array) $secretResponse->json('error-codes', []);

        if (in_array('invalid-input-secret', $errors, true)) {
            return [
                'state'  => 'invalid',
                'reason' => __('Google rejected the Recaptcha Secret. Make sure it is the v2 secret key paired with the site key above.'),
            ];
        }

        if ($this->siteKeyAnswersLegacyV2Probe($siteKey)) {
            return ['state' => 'valid', 'reason' => __('Keys verified.')];
        }

        return [
            'state'  => 'inconclusive',
            'reason' => __('Open a login page and check the "I\'m not a robot" box appears — that confirms the site key. If you see a red error there instead, the key is v3 and login will break, so run "php artisan recaptcha:disable" to switch it back off.'),
        ];
    }

    /**
     * Is this save switching reCAPTCHA on, or changing the keys it runs with?
     *
     * Re-saving an unrelated field on an already-live plugin is not worth a
     * notice about a check that cannot be resolved anyway.
     *
     * @param array<string, mixed> $credentials
     */
    private function recaptchaIsChanging(Plugin $plugin, array $credentials): bool
    {
        if ((int) $plugin->status !== Status::TRUE) {
            return true;
        }

        $stored = $plugin->credentialsArray();

        foreach (['recaptcha_key', 'recaptcha_secret'] as $field) {
            if (trim((string) ($stored[$field] ?? '')) !== trim((string) ($credentials[$field] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Probe the legacy no-JS fallback endpoint, which only answers for older v2
     * site keys. Newer console-issued v2 keys are rejected there too, so a
     * negative answer is a weak signal — never proof of the wrong key type.
     */
    private function siteKeyAnswersLegacyV2Probe(string $siteKey): bool
    {
        try {
            return Http::timeout(15)
                ->get('https://www.google.com/recaptcha/api/fallback', ['k' => $siteKey])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function looksLikeRecaptchaKey(string $value): bool
    {
        return (bool) preg_match('/^6L[0-9A-Za-z_-]{30,}$/', trim($value));
    }

    private function testIpinfoCredentials(array $credentials): array
    {
        $response = Http::acceptJson()
            ->timeout(15)
            ->get('https://ipinfo.io/json', [
                'token' => (string) ($credentials['access_token'] ?? ''),
            ]);

        return $this->pluginTestResponse($response, 'IPinfo');
    }

    private function testCurrencylayerCredentials(array $credentials): array
    {
        $response = Http::acceptJson()
            ->timeout(15)
            ->get('http://api.currencylayer.com/live', [
                'access_key' => (string) ($credentials['api_key'] ?? ''),
                'currencies' => 'USD',
            ]);

        if ($response->json('success') === true) {
            return [
                'status'  => 'success',
                'message' => __('Currencylayer credential validation passed. Live rates endpoint responded.'),
            ];
        }

        return [
            'status'  => 'error',
            'message' => __('Currencylayer credential validation failed: :message', [
                'message' => $response->json('error.info') ?: $this->responseMessage($response),
            ]),
        ];
    }

    private function testCoinmarketcapCredentials(array $credentials): array
    {
        $response = Http::acceptJson()
            ->withHeaders(['X-CMC_PRO_API_KEY' => (string) ($credentials['api_key'] ?? '')])
            ->timeout(15)
            ->get('https://pro-api.coinmarketcap.com/v1/cryptocurrency/map', [
                'listing_status' => 'active',
                'start'          => 1,
                'limit'          => 1,
            ]);

        return $this->pluginTestResponse($response, 'CoinMarketCap');
    }

    private function testPusherCredentials(array $credentials): array
    {
        $appId   = trim((string) ($credentials['pusher_app_id'] ?? ''));
        $key     = trim((string) ($credentials['pusher_app_key'] ?? ''));
        $secret  = trim((string) ($credentials['pusher_app_secret'] ?? ''));
        $cluster = trim((string) ($credentials['pusher_app_cluster'] ?? 'mt1'));
        $path    = '/apps/'.$appId.'/channels';
        $params  = [
            'auth_key'       => $key,
            'auth_timestamp' => time(),
            'auth_version'   => '1.0',
        ];

        ksort($params);

        $query                    = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $params['auth_signature'] = hash_hmac('sha256', "GET\n{$path}\n{$query}", $secret);

        $response = Http::acceptJson()
            ->timeout(15)
            ->get('https://api-'.$cluster.'.pusher.com'.$path, $params);

        return $this->pluginTestResponse($response, 'Pusher');
    }

    private function testTwilioCredentials(array $credentials): array
    {
        $accountSid = trim((string) ($credentials['account_sid'] ?? ''));

        $response = Http::acceptJson()
            ->withBasicAuth($accountSid, (string) ($credentials['auth_token'] ?? ''))
            ->timeout(15)
            ->get('https://api.twilio.com/2010-04-01/Accounts/'.$accountSid.'.json');

        return $this->pluginTestResponse($response, 'Twilio');
    }

    private function pluginTestResponse(Response $response, string $provider): array
    {
        if ($response->successful()) {
            return [
                'status'  => 'success',
                'message' => __(':provider credential validation passed. API connection is reachable.', [
                    'provider' => $provider,
                ]),
            ];
        }

        return [
            'status'  => 'error',
            'message' => __(':provider credential validation failed (:status): :message', [
                'provider' => $provider,
                'status'   => $response->status(),
                'message'  => $this->responseMessage($response),
            ]),
        ];
    }

    private function responseMessage(Response $response): string
    {
        $message = $response->json('message')
            ?? $response->json('error')
            ?? $response->json('error.message')
            ?? $response->json('status.error_message');

        if (is_array($message)) {
            $message = implode(', ', array_filter($message));
        }

        $body = trim((string) ($message ?: $response->body()));

        return $body !== '' ? $body : __('Provider rejected the credential check.');
    }
}
