<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NotificationCategory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one customer wants to hear from us: a channel matrix per category,
 * quiet hours for push, and the amount above which a money notification
 * always gets a push and an email. Security is never switched off, and a
 * customer with no row gets everything on - the behaviour before this
 * existed.
 */
class NotificationSetting extends Model
{
    public const CHANNELS = ['push', 'email', 'sms'];

    protected $fillable = ['user_id', 'channels', 'quiet_from', 'quiet_to', 'large_transaction_threshold'];

    protected function casts(): array
    {
        return ['channels' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The saved row, or an unsaved one with the defaults.
     */
    public static function forUser(User $user): self
    {
        return self::query()->where('user_id', $user->id)->first() ?? new self(['user_id' => $user->id, 'channels' => self::defaultChannels()]);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public static function defaultChannels(): array
    {
        return array_fill_keys(NotificationCategory::ALL, array_fill_keys(self::CHANNELS, true));
    }

    /**
     * The matrix with the defaults underneath and security forced on, so a
     * category added after the row was saved is on rather than missing.
     *
     * @return array<string, array<string, bool>>
     */
    public function matrix(): array
    {
        $saved  = is_array($this->channels) ? $this->channels : [];
        $matrix = self::defaultChannels();

        foreach ($matrix as $category => $channels) {
            foreach ($channels as $channel => $on) {
                $matrix[$category][$channel] = $category === NotificationCategory::SECURITY ? true : (bool) ($saved[$category][$channel] ?? $on);
            }
        }

        return $matrix;
    }

    public function allows(string $category, string $channel): bool
    {
        return (bool) ($this->matrix()[$category][$channel] ?? true);
    }

    /**
     * Whether [at] falls inside the quiet window, which may cross midnight.
     */
    public function inQuietHours(CarbonInterface $at): bool
    {
        if (! $this->quiet_from || ! $this->quiet_to || $this->quiet_from === $this->quiet_to) {
            return false;
        }

        $now  = $at->format('H:i');
        $from = $this->quiet_from;
        $to   = $this->quiet_to;

        return $from < $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }

    /**
     * Whether [amount] (in the site's base currency) is large enough to
     * always be pushed and emailed.
     */
    public function isLarge(string|int|float|null $amount): bool
    {
        if ($amount === null || $this->large_transaction_threshold === null || ! is_numeric($amount)) {
            return false;
        }

        return bccomp((string) $amount, (string) $this->large_transaction_threshold, 8) >= 0;
    }
}
