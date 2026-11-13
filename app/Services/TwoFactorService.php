<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    protected $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
    }

    /**
     * Generate a new secret key.
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Verify a 6-digit code against a secret.
     */
    public function verifyCode(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code, 2);
    }

    /**
     * The otpauth:// address an authenticator app enrols from.
     *
     * The QR code on the website is this address drawn as a picture; the app
     * is handed the address itself, so it can open the authenticator on the
     * same phone rather than ask the customer to photograph their own screen.
     * One definition, so the two always name the same issuer and account.
     *
     * The site title is read with a default: an installation without that
     * setting row would otherwise pass null as the issuer.
     */
    public function otpauthUrl(string $secret, string $label): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) setting('site_title', config('app.name')),
            $label,
            $secret,
        );
    }

    /**
     * Generate a data URI for a QR code (SVG) that encodes the secret.
     *
     * @param string $secret The Google 2FA secret key
     * @param string $label  Typically user's email or name
     */
    public function generateQrCode(string $secret, string $label): string
    {
        $qrCodeUrl = $this->otpauthUrl($secret, $label);

        $renderer = new ImageRenderer(new RendererStyle(200),
            new SvgImageBackEnd);

        $writer  = new Writer($renderer);
        $svgData = $writer->writeString($qrCodeUrl);

        return 'data:image/svg+xml;base64,'.base64_encode($svgData);
    }
}
