<?php

namespace App\Support;

class InstallerServerReport
{
    /**
     * Below this the admin dashboard and the SQL import start failing on
     * memory; it is advice, not a blocker, because hosts often report a
     * lower ini value than the pool actually allows.
     */
    private const RECOMMENDED_MEMORY_BYTES = 256 * 1024 * 1024;

    /**
     * KYC documents and P2P proofs are uploaded at up to 20 MB.
     */
    private const RECOMMENDED_UPLOAD_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly InstallationManager $installer) {}

    /**
     * Everything the Server check screen and the sidebar show, built from the
     * same checks that gate installation so the two can never disagree.
     *
     * @return array{
     *     can_install: bool,
     *     metrics: list<array{key: string, label: string, value: string, hint: string, ok: bool}>,
     *     extensions: list<array{label: string, key: string, ok: bool, help: string}>,
     *     writable: list<array{label: string, key: string, ok: bool, help: string}>,
     *     failed_count: int,
     *     total_count: int,
     *     diagnostics: string,
     *     fix_commands: string,
     *     environment: array{php: string, server: string, laravel: string, domain: string, version: string}
     * }
     */
    public function build(): array
    {
        $status     = $this->installer->status();
        $extensions = $this->extensionRows($status['requirements']);
        $writable   = $this->writableRows($status['writable']);
        $checks     = [...$status['requirements'], ...$status['writable']];
        $failed     = count(array_filter($checks, static fn (array $check): bool => ! $check['status']));

        return [
            'can_install'  => $status['can_install'],
            'metrics'      => $this->metrics($failed, count($checks)),
            'extensions'   => $extensions,
            'writable'     => $writable,
            'failed_count' => $failed,
            'total_count'  => count($checks),
            'diagnostics'  => $this->diagnostics($extensions, $writable),
            'fix_commands' => $this->fixCommands(),
            'environment'  => $this->environment(),
        ];
    }

    /**
     * Parse a php.ini size ("512M", "1G", "-1") into bytes. -1 means
     * unlimited and is returned as PHP_INT_MAX.
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    /**
     * @return list<array{key: string, label: string, value: string, hint: string, ok: bool}>
     */
    private function metrics(int $failed, int $total): array
    {
        $memory = (string) ini_get('memory_limit');
        $upload = (string) ini_get('upload_max_filesize');

        return [
            [
                'key'   => 'php',
                'label' => __('PHP version'),
                'value' => PHP_VERSION,
                'hint'  => __('Requires 8.3 or newer'),
                'ok'    => PHP_VERSION_ID >= 80300,
            ],
            [
                'key'   => 'memory',
                'label' => __('Memory limit'),
                'value' => $memory === '-1' ? __('Unlimited') : $memory,
                'hint'  => __('Recommended 256M or more'),
                'ok'    => self::iniBytes($memory) >= self::RECOMMENDED_MEMORY_BYTES,
            ],
            [
                'key'   => 'upload',
                'label' => __('Upload max'),
                'value' => $upload,
                'hint'  => __('KYC documents need 20M or more'),
                'ok'    => self::iniBytes($upload) >= self::RECOMMENDED_UPLOAD_BYTES,
            ],
            [
                'key'   => 'checks',
                'label' => __('Checks'),
                'value' => $failed > 0
                    ? __(':count failed', ['count' => $failed])
                    : __(':passed / :total', ['passed' => $total, 'total' => $total]),
                'hint' => $failed > 0 ? __('Fix before continuing') : __('All passed'),
                'ok'   => $failed === 0,
            ],
        ];
    }

    /**
     * The PHP version row is left out: the PHP metric card already shows it.
     *
     * @param  list<array{label: string, status: bool, help: string}>          $requirements
     * @return list<array{label: string, key: string, ok: bool, help: string}>
     */
    private function extensionRows(array $requirements): array
    {
        $extensions = (array) config('installer.required_extensions', []);
        $functions  = (array) config('installer.required_functions', []);
        $rows       = [];

        foreach ($requirements as $check) {
            $label = $check['label'];

            if (array_key_exists($label, $extensions)) {
                $rows[] = [
                    'label' => $label,
                    'key'   => (string) $extensions[$label],
                    'ok'    => $check['status'],
                    'help'  => $check['status']
                        ? __('Loaded')
                        : __('Not loaded — enable it in cPanel → Select PHP Version, or in php.ini, then restart PHP.'),
                ];

                continue;
            }

            $function = substr($label, 0, -2);

            if (str_ends_with($label, '()') && array_key_exists($function, $functions)) {
                $rows[] = [
                    'label' => $label,
                    'key'   => 'function',
                    'ok'    => $check['status'],
                    'help'  => $check['status'] ? __('Enabled') : (string) $functions[$function],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{label: string, status: bool, help: string}>          $writable
     * @return list<array{label: string, key: string, ok: bool, help: string}>
     */
    private function writableRows(array $writable): array
    {
        $paths = (array) config('installer.writable_paths', []);
        $rows  = [];

        foreach ($writable as $check) {
            $path = (string) ($paths[$check['label']] ?? '');

            $rows[] = [
                'label' => $check['label'],
                'key'   => $this->relativePath($path),
                'ok'    => $check['status'],
                'help'  => $check['status'] ? __('Writable') : $check['help'],
            ];
        }

        return $rows;
    }

    /**
     * Plain text a buyer can paste into a support ticket. Holds versions,
     * limits and check results only — never a credential.
     *
     * @param list<array{label: string, key: string, ok: bool, help: string}> $extensions
     * @param list<array{label: string, key: string, ok: bool, help: string}> $writable
     */
    private function diagnostics(array $extensions, array $writable): string
    {
        $environment = $this->environment();
        $lines       = [
            'DigiKash installer diagnostics',
            'Generated: '.now()->toIso8601String(),
            'DigiKash: '.$environment['version'].' · Laravel '.$environment['laravel'],
            'PHP: '.PHP_VERSION.' ('.PHP_SAPI.') · Server: '.$environment['server'],
            'Limits: memory_limit='.ini_get('memory_limit')
                .' upload_max_filesize='.ini_get('upload_max_filesize')
                .' post_max_size='.ini_get('post_max_size')
                .' max_execution_time='.ini_get('max_execution_time'),
            '',
            'Extensions and functions:',
        ];

        foreach ($extensions as $row) {
            $lines[] = sprintf('  [%s] %s (%s)', $row['ok'] ? 'OK' : 'MISSING', $row['label'], $row['key']);
        }

        $lines[] = '';
        $lines[] = 'Writable paths:';

        foreach ($writable as $row) {
            $lines[] = sprintf('  [%s] %s (%s)%s', $row['ok'] ? 'OK' : 'FAIL', $row['label'], $row['key'], $row['ok'] ? '' : ' — '.$row['help']);
        }

        return implode("\n", $lines);
    }

    /**
     * The chown/chmod pair for this server: the real project path and the
     * user PHP is running as, so the buyer can paste it unchanged.
     */
    private function fixCommands(): string
    {
        $user = $this->webServerUser();

        return implode("\n", [
            'cd '.str_replace('\\', '/', base_path()),
            "chown -R {$user}:{$user} storage bootstrap/cache",
            'chmod -R 775 storage bootstrap/cache',
        ]);
    }

    private function webServerUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());

            if (is_array($entry) && ($entry['name'] ?? '') !== '') {
                return (string) $entry['name'];
            }
        }

        return 'www-data';
    }

    /**
     * @return array{php: string, server: string, laravel: string, domain: string, version: string}
     */
    private function environment(): array
    {
        $software = trim((string) request()->server('SERVER_SOFTWARE', ''));
        $server   = $software !== '' ? (string) strtok($software, ' ') : PHP_SAPI;

        return [
            'php'     => PHP_VERSION,
            'server'  => $server.' · '.PHP_OS_FAMILY,
            'laravel' => app()->version(),
            'domain'  => request()->getHost(),
            'version' => (string) config('app.version', ''),
        ];
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $path = str_replace('\\', '/', $path);

        if (! str_starts_with($path, $base)) {
            return $path;
        }

        $relative = substr($path, strlen($base));

        return is_dir($path) ? rtrim($relative, '/').'/' : $relative;
    }
}
