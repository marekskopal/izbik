<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Without a broker (`QueueConfig::isEnabled()`), and in the tests: runs each job in the web worker after the response.
 * A failed job is logged, not retried.
 */
final readonly class InlineJobTransport implements JobTransportInterface
{
	public function __construct(private JobHandlers $handlers, private LoggerInterface $logger)
	{
	}

	public function send(QueueEnum $queue, string $payload): void
	{
		try {
			$this->handlers->for($queue)->handle($payload);
		} catch (Throwable $e) {
			$this->logger->error('Background job failed: ' . $e->getMessage(), ['queue' => $queue->value, 'exception' => $e]);
		}
	}
}
