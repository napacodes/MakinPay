<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Reloadly;

use RuntimeException;

/**
 * Reloadly would not issue an access token for the utilities audience.
 *
 * Raised for blank credentials, a refusal from the auth host, a connection
 * that failed or a reply without a token. When it is raised before /pay is
 * contacted, nothing can have been charged, so the driver answers Failed.
 * The message never carries the client secret or a token.
 */
class ReloadlyTokenException extends RuntimeException {}
