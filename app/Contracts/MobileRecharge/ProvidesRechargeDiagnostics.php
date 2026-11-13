<?php

declare(strict_types=1);

namespace App\Contracts\MobileRecharge;

/**
 * A mobile recharge driver that can prove whether its credentials work.
 *
 * "Reloadly is not working" is nearly impossible to act on: a recharge that
 * fails reports a generic message, and the two things that actually go wrong
 * — production keys pointed at the sandbox audience, and a provider with no
 * operator configured — look identical from the outside.
 *
 * Implementing this lets the admin panel probe the provider directly and show
 * the real answer, the same way the virtual card providers already do.
 *
 * Optional on purpose: the base MobileRechargeProviderInterface is unchanged,
 * so a driver that cannot be probed keeps working and simply reports that no
 * diagnostic is available.
 *
 * The return shape matches the virtual card providers' `testConnection()` so
 * one admin UI can render either without knowing which module it came from:
 *
 *   [
 *     'ok'         => bool,                  // credentials accepted and API reachable
 *     'mode'       => 'sandbox'|'live'|null, // resolved environment
 *     'message'    => string,                // human-readable summary
 *     'latency_ms' => int|null,              // round-trip time for the probe
 *     'details'    => array<string,mixed>,   // provider specifics, never secrets
 *   ]
 */
interface ProvidesRechargeDiagnostics
{
    /**
     * Probe the provider's credentials and reachability.
     *
     * Must never throw: a failed probe is a result, not an exception.
     *
     * @return array{ok: bool, mode: ?string, message: string, latency_ms: ?int, details: array<string, mixed>}
     */
    public function testConnection(): array;
}
