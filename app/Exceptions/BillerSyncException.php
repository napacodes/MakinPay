<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Contracts\BillPayment\SyncsBillers;
use RuntimeException;

/**
 * A biller catalogue could not be read from the provider: a transport error,
 * a refused request or a reply that could not be parsed.
 *
 * Raised by {@see SyncsBillers::fetchBillers()}.
 * The synchroniser drains the whole feed before it writes anything, so this
 * aborts a run with nothing written and nothing disabled.
 *
 * The message is shown to an admin in the sync report, so it must never
 * carry a credential, a token or a raw provider body.
 */
class BillerSyncException extends RuntimeException {}
