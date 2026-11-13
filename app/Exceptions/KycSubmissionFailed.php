<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A KYC submission that failed for a reason the applicant did not cause.
 *
 * The other kind of "no" - an active submission already exists, no provider
 * covers the country - stays a NotifyErrorException, because the applicant can
 * act on it. This one means something broke while saving, so the website says
 * so in one safe sentence and the app receives a plain 500.
 *
 * DELIBERATELY NOT CHAINED to the exception that caused it. That is the usual
 * pattern here (X15 does it for deposits), but a database error raised while
 * saving a submission carries the submission's values in its bindings - names,
 * document numbers - and a chained exception is written to the log in full when
 * the framework reports it. The cause is logged instead as its class and
 * location, which is enough to find the fault and holds no identity data.
 */
class KycSubmissionFailed extends RuntimeException {}
