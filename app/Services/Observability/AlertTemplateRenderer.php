<?php

namespace App\Services\Observability;

use App\Models\Observability\ErrorGroup;

/**
 * One template body, rendered into whatever dialect a channel speaks.
 *
 * The admin edits a single body per severity. Each adapter then renders that
 * body its own way — a Discord embed, a Slack block, a Teams card — so a copy
 * change lands everywhere at once instead of being re-typed six times and
 * drifting apart.
 *
 * An unknown variable renders as an em dash, never as raw braces. A message
 * that reaches an on-call phone reading {{gateway.name}} is worse than one
 * that simply has a gap where the gateway would be.
 */
class AlertTemplateRenderer
{
    /**
     * The default body, used until an administrator edits one.
     *
     * Line breaks are meaningful: several channels truncate after the first
     * line, so the level, the code and the title all have to survive there.
     */
    public const DEFAULT_BODY = "{{level.icon}} {{level.name}} · {{error.code}}\n{{error.title}}\n{{error.count}} events · {{error.affected_users}} users · {{gateway.name}}\n{{error.url}}";

    /**
     * Rendered in place of a variable nobody supplied.
     */
    private const MISSING = '—';

    /**
     * Substitute the variables in a body.
     *
     * @param array<string, string|null> $extra
     */
    public function render(string $body, ErrorGroup $group, array $extra = []): string
    {
        $variables = $extra + $this->variables($group);

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
            static function (array $matches) use ($variables): string {
                $value = $variables[$matches[1]] ?? null;

                return ($value === null || $value === '') ? self::MISSING : (string) $value;
            },
            $body
        );
    }

    /**
     * Every variable the template language exposes.
     *
     * @return array<string, string|null>
     */
    public function variables(ErrorGroup $group): array
    {
        $gateway = collect((array) $group->tags)
            ->first(static fn ($tag): bool => is_string($tag) && str_starts_with($tag, 'gateway:'));

        return [
            'error.title'          => $group->title,
            'error.message'        => $group->message,
            'error.count'          => (string) $group->event_count,
            'error.url'            => route('admin.observability.issues.show', $group),
            'error.code'           => $group->error_code,
            'error.affected_users' => (string) $group->affected_user_count,
            'error.status'         => $group->status->label(),
            'error.culprit'        => $group->culprit,
            'gateway.name'         => $gateway === null ? null : substr((string) $gateway, 8),
            'level.name'           => $group->level->badgeLabel(),
            'level.icon'           => $this->icon($group),
            'release.tag'          => $group->last_release,
        ];
    }

    /**
     * The line every channel appends while a group is still receiving events.
     *
     * Throttling to one message per fifteen minutes stops a storm burying the
     * channel, but it also hides that it is a storm. Carrying the live count
     * and these words is what keeps the throttle honest.
     */
    public function stillFiringNotice(ErrorGroup $group): string
    {
        return __(':count events and still firing.', ['count' => $group->event_count]);
    }

    /**
     * A shape, not a colour. Several channels cannot be relied on to colour
     * anything, so severity has to survive as plain text.
     */
    private function icon(ErrorGroup $group): string
    {
        return match ($group->level->badge()) {
            'fatal'   => '[!]',
            'error'   => '[x]',
            'warning' => '[/]',
            default   => '[i]',
        };
    }
}
