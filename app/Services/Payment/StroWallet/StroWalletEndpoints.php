<?php

namespace App\Services\Payment\StroWallet;

/**
 * Where StroWallet's API lives, resolved in one place.
 *
 * The host used to be written out at three call sites — the virtual-card
 * driver, the express-deposit gateway and the admin credential probe — so
 * moving it meant finding all three and hoping none was missed. It is read
 * from here now, and an operator can move it without a code change at all.
 *
 * Mirrors `BitnobProviderSettings`: a shipped default, a `base_url`
 * credential override, and an absolute-HTTPS check on whatever comes back.
 *
 * ## Why the default is still strowallet.com
 *
 * StroWallet's reference now documents the card API as a new "NFC" family
 * served from `https://ziiropay.com` (card KYC, create-nfc-card,
 * fund-withdraw-nfccard, fetch-nfccard-detail, nfc-cards/status, ...) and no
 * longer lists the legacy `create-user` / `create-card` / `fund-card` /
 * `card_withdraw` / `action/status` endpoints at all. The hosts do not agree
 * with the docs. Probed 2026-09-12, with a bad key so nothing could execute:
 *
 *   endpoint                                  strowallet.com       ziiropay.com
 *   POST /api/bitvcard/cardkyc                405                  401 invalid key
 *   GET  /api/bitvcard/cardkycstatus          200 marketing HTML   401 invalid key
 *   POST /api/bitvcard/create-nfc-card        400 (other handler)  422 validation
 *   GET  /api/bitvcard/fetch-nfccard-detail   200 JSON             401 invalid key
 *   POST /api/bitvcard/fund-withdraw-nfccard  200 JSON             401 invalid key
 *   POST /api/bitvcard/nfc-cards/status       200 JSON             404
 *   POST /api/bitvcard/cards/terminate        401 invalid key      404
 *   POST /pay  (documented checkout)          422 validation       405
 *   GET  /api/checkout/status                 404 invalid key      404
 *   every legacy /api/bitvcard/... path       served, validates    301 -> 404 HTML
 *   GET  /express/initiate                    200 validates        404
 *
 * strowallet.com is the only host that answers everything this integration
 * calls: the legacy card endpoints it still runs, the documented
 * fund/withdraw, status and detail endpoints, and the documented checkout.
 * ziiropay.com serves a subset and none of the legacy paths — and because
 * those answer 301 rather than refusing outright, a POST that followed the
 * redirect would arrive with its body dropped, surfacing as "missing
 * required field" instead of as an outage. The drivers therefore call the
 * documented endpoint first and fall back to the legacy one only when the
 * host says the route does not exist, so one host serves both generations
 * and a future removal of either side changes nothing here.
 *
 * ## Moving to it once it is real
 *
 * No code change and no deploy: set `STROWALLET_BASE_URL`, or add a
 * `base_url` key to the gateway's stored credentials to move a single
 * installation. `config/release.php` already keeps `base_url` when it
 * strips gateway secrets, so a per-install override survives an upgrade.
 */
class StroWalletEndpoints
{
    /**
     * The host that actually serves the API. Overridable per environment
     * and per installation; read the class docblock before changing it.
     */
    public const DEFAULT_HOST = 'https://strowallet.com';

    /**
     * Resolve the host, most specific source first: this installation's
     * stored credentials, then the environment, then the shipped default.
     *
     * A blank or malformed override is skipped rather than used, so a typo
     * in one installation's credentials cannot quietly point card issuance
     * at nothing.
     *
     * @param array<string, mixed> $credentials the gateway's stored credentials
     */
    public static function host(array $credentials = []): string
    {
        $candidates = [
            $credentials['base_url'] ?? null,
            config('services.strowallet.base_url'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalize($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return self::DEFAULT_HOST;
    }

    /**
     * Base for every `/api/bitvcard/...` call. Sandbox and live share it:
     * StroWallet switches environment with the `mode` parameter, not the
     * host, so there is deliberately no sandbox variant.
     *
     * @param array<string, mixed> $credentials
     */
    public static function api(array $credentials = []): string
    {
        return self::host($credentials).'/api';
    }

    /**
     * The legacy hosted checkout an express deposit sends the payer to. No
     * longer in StroWallet's reference but still served; used only when the
     * documented checkout below is not.
     *
     * @param array<string, mixed> $credentials
     */
    public static function express(array $credentials = []): string
    {
        return self::host($credentials).'/express/initiate';
    }

    /**
     * The documented checkout: `POST /pay` answers with a `checkout_url`.
     *
     * Reference: https://strowallet.readme.io/reference/checkout-payment
     *
     * @param array<string, mixed> $credentials
     */
    public static function checkout(array $credentials = []): string
    {
        return self::host($credentials).'/pay';
    }

    /**
     * The verification call every checkout callback must be followed by:
     * `GET /api/checkout/status?public_key=..&reference=..`.
     *
     * Reference: https://strowallet.readme.io/reference/checkout-status
     *
     * @param array<string, mixed> $credentials
     */
    public static function checkoutStatus(array $credentials = []): string
    {
        return self::host($credentials).'/api/checkout/status';
    }

    /**
     * Accept `strowallet.com`, `https://strowallet.com` and a trailing
     * slash alike; reject anything that is not an absolute HTTPS host.
     *
     * Plain http is refused rather than upgraded — a public key travelling
     * in a request body has no business on a cleartext hop, and silently
     * "fixing" the scheme would hide that someone configured one.
     */
    private static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $value = 'https://'.$value;
        }

        $value  = rtrim($value, '/');
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host   = parse_url($value, PHP_URL_HOST);

        // parse_url is lenient — it reads a host out of "https://not a url"
        // quite happily — so the whole URL is validated too.
        if ($scheme !== 'https' || ! is_string($host) || $host === '' || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        // A host with no letter in it is a typo, not a hostname — a stray
        // number would otherwise pass as `https://42`. IP literals are still
        // allowed, for an installation pointed at a proxy.
        if (! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match('/[a-z]/i', $host)) {
            return null;
        }

        return $value;
    }
}
