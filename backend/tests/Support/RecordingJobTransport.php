<?php

declare(strict_types=1);

namespace Izbik\Tests\Support;

use Izbik\Service\Queue\JobTransportInterface;
use Izbik\Service\Queue\QueueEnum;
use Override;
use RuntimeException;

/** Keeps the jobs handed to it; `$failNext` fails the next one as an unreachable broker would. */
final class RecordingJobTransport implements JobTransportInterface
{
	/** @var list<array{string, string}> */
	public array $sent = [];

	public function __construct(public bool $failNext = false)
	{
	}

	#[Override]
	public function send(QueueEnum $queue, string $payload): void
	{
		if ($this->failNext) {
			$this->failNext = false;

			throw new RuntimeException('Broker down.');
		}
		$this->sent[] = [$queue->value, $payload];
	}
}
