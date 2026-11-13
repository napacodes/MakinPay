<?php

namespace App\Services\Observability;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * W3C trace context, so an error links to a distributed trace instead of
 * ending the investigation.
 *
 * The ids follow the OpenTelemetry shape — a 32-character trace id and a
 * 16-character span id — and an inbound traceparent header is honoured rather
 * than replaced, so a request that arrived from a gateway or a mobile client
 * keeps the trace it was already part of.
 */
class TraceContext
{
    private ?string $traceId = null;

    private ?string $spanId = null;

    /**
     * Adopt the caller's trace when there is one, mint a new one when there
     * is not. Called once per request; every error captured afterwards shares
     * the result.
     */
    public function bootFromRequest(?Request $request = null): self
    {
        $header = $request?->header('traceparent');

        if (is_string($header) && preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/i', $header, $matches) === 1) {
            $this->traceId = strtolower($matches[1]);
            $this->spanId  = strtolower($matches[2]);

            return $this;
        }

        return $this->start();
    }

    public function start(): self
    {
        $this->traceId = $this->randomHex(32);
        $this->spanId  = $this->randomHex(16);

        return $this;
    }

    public function traceId(): string
    {
        if ($this->traceId === null) {
            $this->start();
        }

        return (string) $this->traceId;
    }

    public function spanId(): string
    {
        if ($this->spanId === null) {
            $this->start();
        }

        return (string) $this->spanId;
    }

    /**
     * The header value to forward on an outbound call, so a provider that
     * also speaks trace context can be lined up against our own timeline.
     */
    public function traceparent(): string
    {
        return '00-'.$this->traceId().'-'.$this->spanId().'-01';
    }

    private function randomHex(int $length): string
    {
        return substr(bin2hex(Str::random($length)), 0, $length);
    }
}
