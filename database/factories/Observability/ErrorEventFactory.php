<?php

namespace Database\Factories\Observability;

use App\Enums\Observability\ErrorLevel;
use App\Models\Observability\ErrorEvent;
use App\Models\Observability\ErrorGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ErrorEvent>
 */
class ErrorEventFactory extends Factory
{
    protected $model = ErrorEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id'         => (string) Str::ulid(),
            'error_group_id'   => ErrorGroup::factory(),
            'level'            => ErrorLevel::Error,
            'context'          => ['stack' => [], 'environment' => 'testing'],
            'request'          => ['method' => 'POST', 'route' => 'api.webhook.stripe'],
            'state'            => ['queries' => [], 'domain_refs' => []],
            'redaction_report' => ['dropped' => 0, 'masked' => 0, 'unclassified' => 0, 'fields' => []],
            'trace_id'         => substr(bin2hex(Str::random(32)), 0, 32),
            'span_id'          => substr(bin2hex(Str::random(16)), 0, 16),
            'release'          => '2.6.1',
            'created_at'       => now(),
        ];
    }
}
