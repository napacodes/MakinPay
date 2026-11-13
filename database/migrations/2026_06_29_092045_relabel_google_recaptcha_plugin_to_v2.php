<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The login forms render the reCAPTCHA v2 checkbox widget, yet the plugin
     * shipped labelled "v3". Admins followed the label, generated v3 keys, and
     * the v2 widget then refused every login. Realign the label with the actual
     * v2 implementation. Credentials are left untouched so live keys survive.
     */
    public function up(): void
    {
        DB::table('plugins')
            ->where('code', 'google-recaptcha')
            ->where('name', 'Google reCAPTCHA v3')
            ->update([
                'name'        => 'Google reCAPTCHA v2',
                'description' => 'reCAPTCHA v2 protects your login forms with the "I\'m not a robot" checkbox. Use reCAPTCHA v2 site and secret keys.',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('plugins')
            ->where('code', 'google-recaptcha')
            ->where('name', 'Google reCAPTCHA v2')
            ->update([
                'name'        => 'Google reCAPTCHA v3',
                'description' => "reCAPTCHA v3 helps you detect abusive traffic on your website without user interaction\r\n",
            ]);
    }
};
