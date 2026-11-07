<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

use RuntimeException;

/** A job no retry can help (an unreadable payload): the consumer parks it in the failed queue at once. */
final class PermanentJobFailure extends RuntimeException
{
}
