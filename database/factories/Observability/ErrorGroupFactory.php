<?php

namespace Database\Factories\Observability;

use App\Enums\Observability\ErrorLevel;
use App\Enums\Observability\ErrorSource;
use App\Enums\Observability\ErrorStatus;
use App\Models\Observability\ErrorGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ErrorGroup>
 */
class ErrorGroupFactory extends Factory
{
    protected $model = ErrorGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seenAt = now()->subMinutes($this->faker->numberBetween(1, 2880));

        return [
            'fingerprint'         => substr(hash('sha256', (string) Str::uuid()), 0, 32),
            'group_id'            => (string) Str::ulid(),
            'title'               => 'SignatureVerificationException',
            'exception_class'     => 'App\Exceptions\SignatureVerificationException',
            'message'             => 'Webhook signature could not be verified.',
            'error_code'          => 'DK-PAY-4012',
            'culprit'             => 'app/Services/Payment/StripeWebhook.php:87 in handle',
            'level'               => ErrorLevel::Error,
            'source'              => ErrorSource::Gateway,
            'status'              => ErrorStatus::New,
            'first_seen'          => $seenAt,
            'last_seen'           => $seenAt,
            'event_count'         => 1,
            'affected_user_count' => 0,
            'tags'                => ['gateway:stripe'],
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status'      => ErrorStatus::Resolved,
            'resolved_at' => now()->subHour(),
        ]);
    }

    public function fatal(): static
    {
        return $this->state(fn (): array => ['level' => ErrorLevel::Critical]);
    }
}
