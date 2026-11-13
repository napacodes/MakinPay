<?php

namespace App\Services;

class PublicWebhookUrlGuard
{
    /**
     * @return null|array{host: string, port: int, ip: string}
     */
    public function resolve(string $url): ?array
    {
        if (! $this->hasSafeShape($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $host = $this->normalizeHost((string) $parts['host']);
        $port = (int) ($parts['port'] ?? 443);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? compact('host', 'port') + ['ip' => $host] : null;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return null;
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === [] || collect($addresses)->contains(fn (string $address): bool => ! $this->isPublicIp($address))) {
            return null;
        }

        return [
            'host' => $host,
            'port' => $port,
            'ip'   => $addresses[0],
        ];
    }

    public function hasSafeShape(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || empty($parts['host'])) {
            return false;
        }

        $host = $this->normalizeHost((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            return false;
        }

        return ! filter_var($host, FILTER_VALIDATE_IP) || $this->isPublicIp($host);
    }

    /**
     * @param array{host: string, port: int, ip: string} $endpoint
     */
    public function curlResolveEntry(array $endpoint): string
    {
        $ip = str_contains($endpoint['ip'], ':') ? '['.$endpoint['ip'].']' : $endpoint['ip'];

        return $endpoint['host'].':'.$endpoint['port'].':'.$ip;
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host, '[]'), '.'));
    }
}
