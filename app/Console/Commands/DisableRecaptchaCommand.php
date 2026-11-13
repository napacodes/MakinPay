<?php

namespace App\Console\Commands;

use App\Constants\Status;
use App\Models\Plugin;
use Illuminate\Console\Command;

/**
 * Emergency recovery for a reCAPTCHA lockout.
 *
 * If the configured reCAPTCHA keys ever stop working at Google (revoked,
 * deleted, or the wrong key type slipped through), the login widget breaks and
 * nobody can sign in. Running `php artisan recaptcha:disable` over SSH turns the
 * plugin off so the login screens drop the widget and access is restored.
 */
class DisableRecaptchaCommand extends Command
{
    protected $signature = 'recaptcha:disable';

    protected $description = 'Disable Google reCAPTCHA on all login screens (recovery from a misconfigured key lockout).';

    public function handle(): int
    {
        $plugin = Plugin::query()->where('code', 'google-recaptcha')->first();

        if (! $plugin) {
            $this->warn('Google reCAPTCHA plugin is not installed. Nothing to disable.');

            return self::SUCCESS;
        }

        if ((int) $plugin->status === Status::FALSE) {
            $this->info('Google reCAPTCHA is already disabled. Login screens do not require it.');

            return self::SUCCESS;
        }

        $plugin->update(['status' => Status::FALSE]);

        $this->info('Google reCAPTCHA has been disabled. Login screens no longer require it.');

        return self::SUCCESS;
    }
}
