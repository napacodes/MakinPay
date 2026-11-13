<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where a stored notification leads, on the website and in the app.
 *
 * The website and the app read the same notifications table. A row's
 * `action_link` is the website's page for it - an absolute URL, as every
 * TemplateNotification writes - and `app_link` is the app's screen, a path
 * such as /home/budgets. Rows written before the two were split carried the
 * app path in `action_link`, and the website's bell sent people to a 404
 * with it; those still lead the app where they should and the website
 * nowhere.
 */
final class NotificationLink
{
    /**
     * @param array<string, mixed> $data
     */
    public static function web(array $data): ?string
    {
        $link = $data['action_link'] ?? null;

        return is_string($link) && preg_match('#^https?://#i', $link) === 1 ? $link : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function app(array $data): ?string
    {
        foreach (['app_link', 'action_link'] as $key) {
            $link = $data[$key] ?? null;

            if (is_string($link) && str_starts_with($link, '/') && ! str_starts_with($link, '//')) {
                return $link;
            }
        }

        return null;
    }
}
