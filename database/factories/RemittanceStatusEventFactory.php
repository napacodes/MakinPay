<?php

namespace Database\Factories;

use App\Enums\RemittanceStatus;
use App\Models\RemittanceStatusEvent;
use App\Models\RemittanceTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemittanceStatusEvent>
 */
class RemittanceStatusEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transfer_id'       => RemittanceTransfer::factory(),
            'from_status'       => RemittanceStatus::Pending->value,
            'to_status'         => RemittanceStatus::Submitted->value,
            'note'              => fake()->sentence(),
            'actor_type'        => 'system',
            'actor_id'          => null,
            'source'            => 'system',
            'external_event_id' => null,
            'payload_hash'      => null,
            'context'           => [],
            'occurred_at'       => now(),
        ];
    }
}
