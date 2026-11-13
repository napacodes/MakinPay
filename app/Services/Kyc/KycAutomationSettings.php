<?php

namespace App\Services\Kyc;

/**
 * Administrator-controlled switches for the automated KYC pipeline.
 *
 * Automation used to be armed only by the KYC_AUTO_ENABLED environment
 * variable. An administrator could configure and activate a provider in the
 * panel and still watch every submission land in the manual queue, with the
 * screen offering no way to change that. The switch and the risk thresholds
 * now live in KYC Verification Settings. The environment values remain the
 * fallback for an installation whose settings rows have not been seeded yet,
 * so upgrading changes nothing until an administrator saves the screen.
 */
final class KycAutomationSettings
{
    public static function enabled(): bool
    {
        return (bool) setting('kyc_auto_verification', config('kyc.auto', false));
    }

    public static function approveBelow(): int
    {
        return (int) setting('kyc_auto_approve_below', config('kyc.thresholds.auto_approve_below', 20));
    }

    public static function rejectAbove(): int
    {
        return (int) setting('kyc_auto_reject_above', config('kyc.thresholds.auto_reject_above', 70));
    }

    /**
     * Whether an applicant must agree before their documents may be sent on.
     *
     * Only automated verification involves a third party. While it is off the
     * documents never leave the site and there is nothing to consent to.
     */
    public static function requiresConsent(): bool
    {
        return self::enabled();
    }

    /**
     * The statement put in front of the applicant, with :site resolved.
     *
     * The exact string returned here is snapshotted onto the submission, so a
     * later edit to the wording never rewrites what someone already agreed to.
     */
    public static function consentText(): string
    {
        $text = trim((string) setting('kyc_consent_text', ''));

        if ($text === '') {
            $text = self::defaultConsentText();
        }

        $site = (string) (setting('site_title', '') ?: config('app.name', ''));

        return trim(str_replace(':site', $site, $text));
    }

    /**
     * Falls back to the wording shipped in config/settings.php so an
     * installation whose settings row has not been seeded still shows a
     * complete statement rather than an empty box.
     */
    private static function defaultConsentText(): string
    {
        foreach ((array) config('settings.kyc_settings.elements', []) as $element) {
            if (($element['key'] ?? null) === 'kyc_consent_text') {
                return (string) ($element['value'] ?? '');
            }
        }

        return '';
    }
}
