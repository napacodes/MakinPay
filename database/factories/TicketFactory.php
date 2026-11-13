<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid'        => strtoupper('#'.Str::random(7)),
            'user_id'     => User::factory(),
            'category_id' => null,
            'title'       => fake()->sentence(4),
            'message'     => fake()->paragraph(),
            'attachment'  => null,
            'priority'    => TicketPriority::MEDIUM,
            'status'      => TicketStatus::PENDING,
            'is_resolved' => false,
        ];
    }
}
