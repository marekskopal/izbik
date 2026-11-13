<?php

declare(strict_types=1);

namespace Izbik\Service\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;
use const FILE_APPEND;
use const PHP_EOL;

/**
 * Wraps the application logger so a logging fault can never abort the caller.
 *
 * Tracy throws when it cannot write its log file (unwritable directory, full disk). In a
 * long-running consumer that used to be fatal: the throw escaped the consume callback before the
 * message was acked or nacked, so the message came back on the next connection, killed the worker
 * again, and supervisord burned its start retries and left the queue without a consumer. The
 * fallback writes to stderr, which supervisord forwards to the container log.
 */
final class SafeLogger extends AbstractLogger
{
	public function __construct(private readonly LoggerInterface $logger, private readonly string $fallbackStream = 'php://stderr')
	{
	}

	/**
	 * @param mixed $level
	 * @param array<mixed> $context
	 */
	public function log(mixed $level, string|Stringable $message, array $context = []): void
	{
		try {
			$this->logger->log($level, $message, $context);
		} catch (Throwable $e) {
			$line = implode(' ', [
				date('[Y-m-d H-i-s]'),
				'LOGGER FAILED: ' . $e->getMessage(),
				'| dropped ' . (is_string($level) ? strtoupper($level) : 'LOG') . ': ' . $message,
			]);

			try {
				file_put_contents($this->fallbackStream, $line . PHP_EOL, FILE_APPEND);
			} catch (Throwable) {
				// Nothing left to write to: never let a logging fault propagate to the caller.
			}
		}
	}
}
