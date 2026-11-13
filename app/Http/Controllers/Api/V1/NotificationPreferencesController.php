<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\NotificationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The notification-preferences matrix: which channels carry which
 * categories, quiet hours for push, and the amount above which a money
 * notification is always pushed and mailed. Security cannot be switched
 * off; the answer always shows it on whatever was sent.
 */
class NotificationPreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->body(NotificationSetting::forUser($request->user())));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channels'                    => ['sometimes', 'array'],
            'channels.*'                  => ['array'],
            'channels.*.*'                => ['boolean'],
            'quiet_from'                  => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_to'                    => ['sometimes', 'nullable', 'date_format:H:i', Rule::requiredIf(filled($request->input('quiet_from')))],
            'large_transaction_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $settings = NotificationSetting::forUser($request->user());

        if (array_key_exists('channels', $validated)) {
            $matrix = $settings->matrix();
            foreach ($validated['channels'] as $category => $channels) {
                if (! NotificationCategory::isValid((string) $category) || $category === NotificationCategory::SECURITY) {
                    continue;
                }
                foreach ((array) $channels as $channel => $on) {
                    if (in_array($channel, NotificationSetting::CHANNELS, true)) {
                        $matrix[$category][$channel] = (bool) $on;
                    }
                }
            }
            $settings->channels = $matrix;
        }

        if (array_key_exists('quiet_from', $validated) || array_key_exists('quiet_to', $validated)) {
            $settings->quiet_from = $validated['quiet_from'] ?? null;
            $settings->quiet_to   = $validated['quiet_to']   ?? null;
        }

        if (array_key_exists('large_transaction_threshold', $validated)) {
            $settings->large_transaction_threshold = $validated['large_transaction_threshold'];
        }

        $settings->save();

        return ApiResponse::ok($this->body($settings->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(NotificationSetting $settings): array
    {
        $base = strtoupper((string) siteCurrency());

        return [
            'categories'                  => NotificationCategory::ALL,
            'channels'                    => $settings->matrix(),
            'locked'                      => [NotificationCategory::SECURITY],
            'quiet_from'                  => $settings->quiet_from,
            'quiet_to'                    => $settings->quiet_to,
            'large_transaction_threshold' => $settings->large_transaction_threshold === null ? null : ApiMoney::amount((string) $settings->large_transaction_threshold, $base),
            'currency'                    => $base,
        ];
    }
}
