<?php

namespace App\Services\Observability\Channels;

use App\Models\Observability\ErrorGroup;
use App\Models\Observability\ErrorNotificationChannel;

/**
 * One destination for an alert.
 *
 * An adapter turns the rendered template body into the payload its provider
 * expects and posts it. It never decides whether to send — routing, throttling
 * and quiet hours are settled before it is reached — so a channel that is
 * being difficult cannot change who gets paged.
 */
interface ChannelAdapter
{
    /**
     * The stable machine key: slack, discord, teams.
     */
    public function key(): string;

    public function label(): string;

    /**
     * The credentials this channel needs, keyed by field name.
     *
     * The connect form, its validation rules and the encrypted payload are
     * all built from this one declaration, so a new channel cannot ship a
     * form that asks for something the adapter never reads.
     *
     * secret marks a value that must never be echoed back into the form.
     *
     * @return array<string, array{label: string, rule: string, secret: bool, help: string}>
     */
    public function credentialFields(): array;

    /**
     * The payload this provider expects, for the given rendered body.
     *
     * Kept separate from send() so the channels screen can show a live preview
     * of the real payload rather than an approximation of it.
     *
     * @return array<string, mixed>
     */
    public function payload(ErrorGroup $group, string $body, ErrorNotificationChannel $channel): array;

    /**
     * Deliver, and report what happened.
     *
     * Never throws: a provider being down is an expected outcome here, and the
     * delivery log needs the status code more than the caller needs a stack
     * trace.
     */
    public function send(ErrorGroup $group, string $body, ErrorNotificationChannel $channel): ChannelResult;
}
