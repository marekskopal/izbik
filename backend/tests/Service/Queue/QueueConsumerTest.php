<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Queue;

use Izbik\Service\Queue\JobHandlerInterface;
use Izbik\Service\Queue\JobOutcomeEnum;
use Izbik\Service\Queue\PermanentJobFailure;
use Izbik\Service\Queue\QueueConsumer;
use Izbik\Service\Queue\QueueEnum;
use Izbik\Service\Queue\RetryPolicy;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\IntegrationTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Throwable;

#[CoversClass(QueueConsumer::class)]
#[CoversClass(RetryPolicy::class)]
final class QueueConsumerTest extends IntegrationTestCase
{
	public function testADoneJobIsAcknowledged(): void
	{
		self::assertSame(JobOutcomeEnum::Done, $this->outcome(null, []));
	}

	public function testAFailedJobIsRetriedUntilItsLastAttempt(): void
	{
		$failure = new RuntimeException('SMTP down.');

		self::assertSame(JobOutcomeEnum::Retry, $this->outcome($failure, []));
		self::assertSame(JobOutcomeEnum::Retry, $this->outcome($failure, self::rejected(RetryPolicy::MaxAttempts - 2)));
		self::assertSame(JobOutcomeEnum::Park, $this->outcome($failure, self::rejected(RetryPolicy::MaxAttempts - 1)));
	}

	public function testOnlyRejectionsFromItsOwnQueueCount(): void
	{
		$headers = [
			'x-death' => [
				['queue' => QueueEnum::AccountMail->retryQueue(), 'reason' => 'expired', 'count' => 9],
				['queue' => 'izbik.other', 'reason' => 'rejected', 'count' => 9],
				['queue' => QueueEnum::AccountMail->value, 'reason' => 'rejected', 'count' => 2],
			]];

		self::assertSame(2, RetryPolicy::previousAttempts($headers, QueueEnum::AccountMail));
		self::assertSame(0, RetryPolicy::previousAttempts(['x-death' => 'junk'], QueueEnum::AccountMail));
	}

	public function testAHandlerThatCannotBeBuiltCountsAsAFailedAttempt(): void
	{
		$consumer = AppHarness::container()->get(QueueConsumer::class);
		assert($consumer instanceof QueueConsumer);
		$unbuildable = static function (): JobHandlerInterface {
			throw new RuntimeException('Bad mailer DSN.');
		};

		self::assertSame(JobOutcomeEnum::Retry, $consumer->outcome($unbuildable, QueueEnum::AccountMail, '{}', []));
		self::assertSame(
			JobOutcomeEnum::Park,
			$consumer->outcome($unbuildable, QueueEnum::AccountMail, '{}', self::rejected(RetryPolicy::MaxAttempts - 1)),
		);
	}

	public function testAJobNoRetryCanHelpIsParkedAtOnce(): void
	{
		self::assertSame(JobOutcomeEnum::Park, $this->outcome(new PermanentJobFailure('Unreadable.'), []));
	}

	/** @return array<string, mixed> */
	private static function rejected(int $count): array
	{
		return ['x-death' => [['queue' => QueueEnum::AccountMail->value, 'reason' => 'rejected', 'count' => $count]]];
	}

	/** @param array<string, mixed> $headers */
	private function outcome(?Throwable $failure, array $headers): JobOutcomeEnum
	{
		$consumer = AppHarness::container()->get(QueueConsumer::class);
		assert($consumer instanceof QueueConsumer);
		$handler = new readonly class ($failure) implements JobHandlerInterface {
			public function __construct(private ?Throwable $failure)
			{
			}

			#[Override]
			public function handle(string $payload): void
			{
				if ($this->failure !== null) {
					throw $this->failure;
				}
			}
		};

		return $consumer->outcome(static fn (): JobHandlerInterface => $handler, QueueEnum::AccountMail, '{}', $headers);
	}
}
