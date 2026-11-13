<?php

namespace App\Services\Observability\Channels;

/**
 * What one delivery attempt did.
 *
 * The retryable flag is the important field. A 429 or a 502 is worth trying
 * again; a 401 or a 404 means the credentials or the URL are wrong, and
 * retrying that five times only delays telling the administrator something
 * they have to fix by hand.
 */
class ChannelResult
{
    public function __construct(
        public readonly bool $delivered,
        public readonly ?int $statusCode = null,
        public readonly string $summary = '',
        public readonly int $durationMs = 0,
    ) {}

    public static function ok(int $statusCode, int $durationMs): self
    {
        return new self(true, $statusCode, 'ok', $durationMs);
    }

    public static function failed(?int $statusCode, string $summary, int $durationMs = 0): self
    {
        return new self(false, $statusCode, $summary, $durationMs);
    }

    /**
     * Whether trying again could plausibly succeed.
     *
     * A missing status code means the request never got an answer — a timeout
     * or a DNS failure — which is exactly the case retrying exists for.
     */
    public function isRetryable(): bool
    {
        if ($this->delivered) {
            return false;
        }

        if ($this->statusCode === null) {
            return true;
        }

        return $this->statusCode === 429 || $this->statusCode >= 500;
    }

    /**
     * A permanent rejection. The channel is marked failing and an
     * infrastructure error is raised, because nobody is being paged until a
     * human fixes the credentials.
     */
    public function isPermanentRejection(): bool
    {
        return in_array($this->statusCode, [401, 403, 404], true);
    }
}
