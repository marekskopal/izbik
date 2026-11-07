<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Queue;

use Izbik\Service\Queue\JobQueue;
use Izbik\Service\Queue\QueueEnum;
use Izbik\Tests\Support\RecordingJobTransport;
use JsonSerializable;
use Laminas\Diactoros\Response\EmptyResponse;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(JobQueue::class)]
final class JobQueueTest extends TestCase
{
	public function testJobsGoOutAfterASuccessfulResponseOnly(): void
	{
		$transport = self::recordingTransport();
		$queue = new JobQueue($transport, new NullLogger());

		$queue->push(QueueEnum::AccountMail, self::job(['n' => 1]));
		$queue->flushAfter(new EmptyResponse(500));
		$queue->push(QueueEnum::AccountMail, self::job(['n' => 2]));
		$queue->flushAfter(new EmptyResponse(422));
		self::assertSame([], $transport->sent);

		$queue->push(QueueEnum::AccountMail, self::job(['n' => 3, 'url' => 'https://x.test/a?b=c']));
		$queue->flushAfter(new EmptyResponse(200));
		$queue->flushAfter(new EmptyResponse(200));

		self::assertSame([['izbik.account-mail', '{"n":3,"url":"https://x.test/a?b=c"}']], $transport->sent);
	}

	public function testResetDropsTheJobsOfTheRequestBefore(): void
	{
		$transport = self::recordingTransport();
		$queue = new JobQueue($transport, new NullLogger());

		$queue->push(QueueEnum::AccountMail, self::job(['n' => 1]));
		$queue->reset();
		$queue->flush();

		self::assertSame([], $transport->sent);
	}

	public function testATransportFailureDoesNotStopTheOtherJobs(): void
	{
		$transport = self::recordingTransport(failFirst: true);
		$queue = new JobQueue($transport, new NullLogger());

		$queue->push(QueueEnum::AccountMail, self::job(['n' => 1]));
		$queue->push(QueueEnum::AccountMail, self::job(['n' => 2]));
		$queue->flush();

		self::assertSame([['izbik.account-mail', '{"n":2}']], $transport->sent);
	}

	/** @param array<string, mixed> $data */
	private static function job(array $data): JsonSerializable
	{
		return new readonly class ($data) implements JsonSerializable {
			/** @param array<string, mixed> $data */
			public function __construct(private array $data)
			{
			}

			/** @return array<string, mixed> */
			#[Override]
			public function jsonSerialize(): array
			{
				return $this->data;
			}
		};
	}

	private static function recordingTransport(bool $failFirst = false): RecordingJobTransport
	{
		return new RecordingJobTransport($failFirst);
	}
}
