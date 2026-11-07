<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Background jobs pushed during a request. They are held until the client has its response and handed to the
 * transport only when the request succeeded (`flushAfter()`): a request answered with an error, whose changes may not
 * have been made, starts no job. Nor does a job tell by the response's timing that it exists (a password reset for an
 * unknown address pushes none). The worker resets it before each request.
 */
final class JobQueue
{
	/** @var list<array{QueueEnum, string}> */
	private array $jobs = [];

	public function __construct(private readonly JobTransportInterface $transport, private readonly LoggerInterface $logger)
	{
	}

	public function push(QueueEnum $queue, JsonSerializable $job): void
	{
		$this->jobs[] = [$queue, json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
	}

	public function reset(): void
	{
		$this->jobs = [];
	}

	/** Hands the jobs to the transport after a successful response, and drops them after an error. */
	public function flushAfter(ResponseInterface $response): void
	{
		if ($response->getStatusCode() >= 400) {
			$this->reset();

			return;
		}
		$this->flush();
	}

	public function flush(): void
	{
		$jobs = $this->jobs;
		$this->jobs = [];
		foreach ($jobs as [$queue, $payload]) {
			try {
				$this->transport->send($queue, $payload);
			} catch (Throwable $e) {
				$this->logger->error(
					'Queueing a background job failed: ' . $e->getMessage(),
					['queue' => $queue->value, 'exception' => $e],
				);
			}
		}
	}
}
