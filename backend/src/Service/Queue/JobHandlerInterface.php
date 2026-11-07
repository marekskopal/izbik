<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

/**
 * Does one queue's jobs (`JobHandlers` maps them). A thrown exception has the job retried later, a
 * `PermanentJobFailure` parks it in the failed queue at once; a job that has become pointless returns without one.
 */
interface JobHandlerInterface
{
	/** @param string $payload the job's JSON */
	public function handle(string $payload): void;
}
