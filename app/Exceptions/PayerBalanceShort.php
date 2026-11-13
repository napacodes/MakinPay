<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * POS6: the payer's wallet cannot cover a merchant payment, and the caller
 * asked for the checkout to stay open rather than be failed.
 *
 * A NotifyErrorException, so it means what that means: the attempt never
 * started, nothing moved, the checkout is still pending for another try. The
 * till catches it by name to put the sale back to waiting with the reason;
 * hosted checkout never asks for it and fails the checkout as before.
 */
final class PayerBalanceShort extends NotifyErrorException {}
