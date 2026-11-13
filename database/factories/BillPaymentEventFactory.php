<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillPayment\BillPaymentActorType;
use App\Enums\BillPayment\BillPaymentDispatchState;
use App\Enums\BillPayment\BillPaymentStatus;
use App\Models\BillPayment;
use App\Models\BillPaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The first event of a payment: created, pending and reserved, by the system.
 *
 * @extends Factory<BillPaymentEvent>
 */
class BillPaymentEventFactory extends Factory
{
    protected $model = BillPaymentEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bill_payment_id'     => BillPayment::factory(),
            'from_status'         => null,
            'to_status'           => BillPaymentStatus::Pending->value,
            'from_dispatch_state' => null,
            'to_dispatch_state'   => BillPaymentDispatchState::Reserved->value,
            'actor_type'          => BillPaymentActorType::System->value,
            'actor_id'            => null,
            'note'                => 'Submitted',
            'external_event_id'   => null,
            'payload'             => null,
        ];
    }
}
