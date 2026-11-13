<?php

declare(strict_types=1);

namespace App\Contracts\BillPayment;

/**
 * Marker: a person on the admin side pays the biller and records the receipt.
 *
 * A driver that carries it makes the payments it takes `fulfilment =
 * operator`. Its pay() only queues the payment, which then waits in the admin
 * queue until an operator marks it paid or failed. Fulfilment is decided when
 * the money is reserved and never changes afterwards.
 */
interface FulfilledByOperator {}
