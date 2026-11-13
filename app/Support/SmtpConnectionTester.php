<?php

namespace App\Support;

use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

class SmtpConnectionTester
{
    /**
     * A mailer of its own, so a probe never disturbs the `smtp` mailer the
     * rest of the application sends through, nor inherits a MAIL_URL DSN
     * from .env that would override what the buyer typed.
     */
    private const PROBE_MAILER = 'installer_smtp_probe';

    /**
     * A wrong host or a firewalled port otherwise hangs for the socket
     * default of 60 seconds, which the web server usually kills first.
     */
    private const TIMEOUT_SECONDS = 15;

    /**
     * Resolved when a test runs, not when the class is built: the installer
     * middleware builds InstallationManager (and so this) on every request,
     * and only the Email step ever needs a mailer.
     */
    private function mailManager(): MailManager
    {
        return app(MailManager::class);
    }

    /**
     * Connect and sign in to the SMTP server, and when a recipient is given
     * send a real message through it.
     *
     * The mailer is built by Laravel's own MailManager from the same keys
     * AppConfigService applies at runtime, so a pass here means the installed
     * site sends the same way: port 465 as implicit TLS, any other port with
     * STARTTLS whenever the server offers it.
     *
     * @param  array{mail_host: string, mail_port: int|string, mail_secure?: string, mail_username: string, mail_password: string, email_from_address?: string, email_from_name?: string} $settings
     * @return array{ok: bool, status: string, message: string, checks: list<array{label: string, status: string, detail: string}>}
     */
    public function verify(array $settings, ?string $recipient = null): array
    {
        $host     = trim((string) $settings['mail_host']);
        $port     = (int) $settings['mail_port'];
        $password = (string) $settings['mail_password'];
        $endpoint = $host.':'.$port;
        $stage    = 'connect';
        $checks   = [];

        Config::set('mail.mailers.'.self::PROBE_MAILER, [
            'transport'  => 'smtp',
            'url'        => null,
            'host'       => $host,
            'port'       => $port,
            'encryption' => (string) ($settings['mail_secure'] ?? 'tls'),
            'username'   => (string) $settings['mail_username'],
            'password'   => $password,
            'timeout'    => self::TIMEOUT_SECONDS,
        ]);

        $mailManager = $this->mailManager();
        $mailManager->purge(self::PROBE_MAILER);

        $transport = null;

        try {
            $mailer    = $mailManager->mailer(self::PROBE_MAILER);
            $transport = $mailer->getSymfonyTransport();

            if ($transport instanceof SmtpTransport) {
                $transport->start();
            }

            $checks[] = $this->check(__('Server connection'), 'success', __('Connected to :endpoint.', ['endpoint' => $endpoint]));
            $checks[] = $this->check(__('Authentication'), 'success', __('The mail server accepted the username and password.'));

            if ($recipient === null || trim($recipient) === '') {
                return $this->result(true, __('SMTP connection verified.'), $checks);
            }

            $stage       = 'send';
            $fromAddress = trim((string) ($settings['email_from_address'] ?? ''));
            $fromName    = trim((string) ($settings['email_from_name'] ?? ''));

            $mailer->raw($this->testMessageBody($endpoint), function (Message $message) use ($recipient, $fromAddress, $fromName): void {
                $message->to(trim($recipient))
                    ->subject(__('Digikash installer — your mail settings work'));

                if ($fromAddress !== '') {
                    $message->from($fromAddress, $fromName !== '' ? $fromName : null);
                }
            });

            $checks[] = $this->check(__('Test email'), 'success', __('Sent to :email — check the inbox and the spam folder.', ['email' => trim($recipient)]));

            return $this->result(true, __('SMTP settings verified and a test email was sent.'), $checks);
        } catch (Throwable $e) {
            return $this->failure($e, $stage, $endpoint, $password, $checks);
        } finally {
            try {
                if ($transport instanceof SmtpTransport) {
                    $transport->stop();
                }
            } catch (Throwable) {
                //
            }

            $mailManager->purge(self::PROBE_MAILER);
            Config::set('mail.mailers.'.self::PROBE_MAILER, null);
        }
    }

    /**
     * @param  list<array{label: string, status: string, detail: string}>                                                           $checks
     * @return array{ok: bool, status: string, message: string, checks: list<array{label: string, status: string, detail: string}>}
     */
    private function failure(Throwable $e, string $stage, string $endpoint, string $password, array $checks): array
    {
        $error = $this->redact($e->getMessage(), $password);
        $lower = strtolower($error);

        Log::warning('Installer SMTP test failed', [
            'endpoint' => $endpoint,
            'stage'    => $stage,
            'error'    => $error,
        ]);

        if ($stage === 'send') {
            $checks[] = $this->check(__('Test email'), 'error', $error);

            return $this->result(false, __('Signed in to the mail server, but it refused to send the test email. Check the From address — most providers only send from an address they host.'), $checks);
        }

        if (str_contains($lower, 'authenticate') || preg_match('/\b53[45]\b/', $error) === 1) {
            return $this->result(false, __('The mail server rejected the username or password.'), [
                $this->check(__('Server connection'), 'success', __('Connected to :endpoint.', ['endpoint' => $endpoint])),
                $this->check(__('Authentication'), 'error', $error),
            ]);
        }

        if (preg_match('/\b(ssl|tls|crypto|certificate)\b/', $lower) === 1) {
            return $this->result(false, __('Could not open a secure connection to :endpoint. Use port 465 with SSL or 587 with TLS.', ['endpoint' => $endpoint]), [
                $this->check(__('Encryption'), 'error', $error),
            ]);
        }

        return $this->result(false, __('Could not reach the mail server at :endpoint.', ['endpoint' => $endpoint]), [
            $this->check(__('Server connection'), 'error', $error),
        ]);
    }

    /**
     * Provider error text can echo what the client sent. The password must
     * never reach the browser or the log, in plain or base64 form.
     */
    private function redact(string $text, string $password): string
    {
        if ($password === '') {
            return $text;
        }

        return str_replace([$password, base64_encode($password)], '********', $text);
    }

    private function testMessageBody(string $endpoint): string
    {
        return __('This is a test email from the Digikash installer.')."\n\n"
            .__('If you are reading this, the SMTP settings entered during installation are working.')."\n\n"
            .__('Server: :endpoint', ['endpoint' => $endpoint])."\n"
            .__('Sent at: :time', ['time' => now()->toDayDateTimeString()])."\n\n"
            .__('No action is needed. You can delete this email.');
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function check(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }

    /**
     * @param  list<array{label: string, status: string, detail: string}>                                                           $checks
     * @return array{ok: bool, status: string, message: string, checks: list<array{label: string, status: string, detail: string}>}
     */
    private function result(bool $ok, string $message, array $checks): array
    {
        return [
            'ok'      => $ok,
            'status'  => $ok ? 'success' : 'error',
            'message' => $message,
            'checks'  => $checks,
        ];
    }
}
