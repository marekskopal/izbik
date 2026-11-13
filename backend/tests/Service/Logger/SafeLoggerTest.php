<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Logger;

use Izbik\Service\Logger\SafeLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;

#[CoversClass(SafeLogger::class)]
final class SafeLoggerTest extends TestCase
{
	public function testDelegatesToTheWrappedLogger(): void
	{
		$inner = new class extends AbstractLogger {
			/** @var list<array{mixed, string, array<mixed>}> */
			public array $records = [];

			/**
			 * @param mixed $level
			 * @param array<mixed> $context
			 */
			public function log(mixed $level, string|Stringable $message, array $context = []): void
			{
				$this->records[] = [$level, (string) $message, $context];
			}
		};

		(new SafeLogger($inner))->error('boom', ['jobId' => 1]);

		self::assertSame([[LogLevel::ERROR, 'boom', ['jobId' => 1]]], $inner->records);
	}

	public function testALoggerFaultDoesNotPropagateAndIsReportedOnTheFallbackStream(): void
	{
		$inner = new class extends AbstractLogger {
			/**
			 * @param mixed $level
			 * @param array<mixed> $context
			 */
			public function log(mixed $level, string|Stringable $message, array $context = []): void
			{
				throw new RuntimeException("Unable to write to log file '/app/log/error.log'. Is directory writable?");
			}
		};

		$fallback = tempnam(sys_get_temp_dir(), 'safe-logger-');
		self::assertIsString($fallback);

		// Tracy throws when its log directory is not writable. A queue consumer has to reach its
		// ack/nack after this, so the fault must not escape — but it must not vanish either.
		(new SafeLogger($inner, $fallback))->error('Job failed: boom');

		$written = file_get_contents($fallback);
		unlink($fallback);

		self::assertIsString($written);
		self::assertStringContainsString('LOGGER FAILED: Unable to write to log file', $written);
		self::assertStringContainsString('dropped ERROR: Job failed: boom', $written);
	}
}
