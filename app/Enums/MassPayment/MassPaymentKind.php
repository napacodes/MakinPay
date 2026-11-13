<?php

declare(strict_types=1);

namespace App\Enums\MassPayment;

/**
 * What a mass payment batch pays out to.
 *
 *   Wallet   every line credits a DigiKash wallet (GF4).
 *   Payout   every line is sent to an external account through one withdraw
 *            method (GF5).
 *
 * A batch never mixes the two. The column is `mass_payment_batches.kind`, a
 * plain string(16) defaulting to `wallet`. Every customer route and service
 * lookup of one kind is scoped to that kind, so a batch of the other kind is
 * a 404 there rather than something a wallet flow could act on.
 */
enum MassPaymentKind: string
{
    case Wallet = 'wallet';
    case Payout = 'payout';
}
