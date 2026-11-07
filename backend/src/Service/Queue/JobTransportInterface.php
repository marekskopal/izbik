<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

/** Where `JobQueue` hands its jobs once the request is over: the broker (`AmqpJobTransport`) or the handlers right away. */
interface JobTransportInterface
{
	/** @param string $payload the job's JSON */
	public function send(QueueEnum $queue, string $payload): void;
}
