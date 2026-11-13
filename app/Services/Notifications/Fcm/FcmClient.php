<?php

declare(strict_types=1);

namespace App\Services\Notifications\Fcm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Talking to Firebase Cloud Messaging over the HTTP v1 API.
 *
 * Written against the API directly rather than through a package, for two
 * reasons worth stating because the package is the obvious choice. The first
 * is that adding a dependency is the operator's decision, not ours - this
 * application ships to customers who install it themselves, and its deploy
 * bundle carries no composer.json, so a new package is a manual step on every
 * server. The second is that the whole surface needed here is one signed JWT
 * and one POST; the package's value is the rest of Firebase, which this does
 * not use.
 *
 * If that trade is revisited, swapping this for
 * laravel-notification-channels/fcm is a change to FcmChannel and nothing
 * else - which is why the channel talks to this and not to Http directly.
 *
 * The retired server-key endpoint is deliberately not implemented: an
 * installation configured with one would fail at the first send rather than
 * at configuration time.
 */
class FcmClient
{
    private const TOKEN_CACHE_KEY = 'fcm:access-token';

    /**
     * Whether this installation can send at all.
     *
     * Read before the channel is offered, so a site with no mobile app simply
     * does not have the channel rather than having one that always fails.
     */
    public function isConfigured(): bool
    {
        return filled(config('fcm.project_id')) && $this->serviceAccount() !== null;
    }

    /**
     * Send one message to one registration token.
     *
     * Returns false for a token FCM says is gone, so the caller can forget it.
     * Anything else throws, because a transport fault is worth a retry and a
     * dead handset is not.
     */
    public function send(string $registrationToken, array $message): bool
    {
        $projectId = (string) config('fcm.project_id');

        $response = Http::withToken($this->accessToken())
            ->timeout((int) config('fcm.timeout_seconds', 10))
            ->post(
                str_replace(':project', $projectId, (string) config('fcm.endpoint')),
                ['message' => ['token' => $registrationToken] + $message],
            );

        if ($response->successful()) {
            return true;
        }

        /*
         * 404 UNREGISTERED and 400 on the token mean this handset will never
         * receive anything again - wiped, or the app uninstalled. Reported as
         * "forget this token" rather than thrown, so a queue does not retry a
         * delivery that cannot succeed.
         */
        if (in_array($response->status(), [400, 404], true)) {
            Log::info('FCM rejected a registration token', [
                'status' => $response->status(),
                // The token is an address rather than a secret, but it
                // identifies an install - so only its shape is recorded.
                'token_length' => strlen($registrationToken),
            ]);

            return false;
        }

        throw new RuntimeException('FCM send failed with status '.$response->status());
    }

    /**
     * A cached OAuth2 access token for the service account.
     *
     * Cached because every notification would otherwise cost an extra round
     * trip to Google, and because the assertion is signed with a private key -
     * work worth doing once an hour rather than once a message.
     */
    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $account = $this->serviceAccount();

        if ($account === null) {
            throw new RuntimeException('FCM is not configured.');
        }

        $response = Http::asForm()
            ->timeout((int) config('fcm.timeout_seconds', 10))
            ->post((string) config('fcm.oauth_endpoint'), [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $this->assertion($account),
            ]);

        if (! $response->successful()) {
            // Deliberately not logging the body: a failed exchange echoes back
            // parts of the assertion.
            throw new RuntimeException('FCM token exchange failed with status '.$response->status());
        }

        $token = (string) ($response->json('access_token') ?? '');

        if ($token === '') {
            throw new RuntimeException('FCM token exchange returned no access token.');
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, (int) config('fcm.token_ttl_seconds', 3540));

        return $token;
    }

    /**
     * The signed JWT Google exchanges for an access token.
     *
     * RS256 through ext-openssl, which this application already requires - so
     * this adds no dependency of its own.
     */
    private function assertion(array $account): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $claims = [
            'iss'   => $account['client_email'],
            'scope' => (string) config('fcm.scope'),
            'aud'   => (string) config('fcm.oauth_endpoint'),
            'iat'   => $now,
            // An hour is Google's maximum. Shorter means re-signing more often
            // for no gain, since the access token it buys is cached anyway.
            'exp' => $now + 3600,
        ];

        $payload = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR))
            .'.'.$this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));

        $signature = '';

        if (! openssl_sign($payload, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the FCM assertion.');
        }

        return $payload.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * The service account, from a file or from inline JSON.
     *
     * Returns null rather than throwing when it is absent or unreadable: an
     * installation with no mobile app has no service account, and that is a
     * configuration state rather than a fault.
     *
     * @return array{client_email: string, private_key: string}|null
     */
    private function serviceAccount(): ?array
    {
        try {
            $json = (string) config('fcm.credentials');

            if ($json === '' && filled(config('fcm.credentials_path'))) {
                $path = (string) config('fcm.credentials_path');

                if (! is_readable($path)) {
                    return null;
                }

                $json = (string) file_get_contents($path);
            }

            if ($json === '') {
                return null;
            }

            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)
                || ! filled($decoded['client_email'] ?? null)
                || ! filled($decoded['private_key'] ?? null)) {
                return null;
            }

            return [
                'client_email' => (string) $decoded['client_email'],
                'private_key'  => (string) $decoded['private_key'],
            ];
        } catch (Throwable) {
            // Never log the exception: it can carry key material.
            return null;
        }
    }
}
