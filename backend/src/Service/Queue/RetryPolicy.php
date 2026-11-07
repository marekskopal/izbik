<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

/**
 * How often a failed job is tried: `MaxAttempts` times in all, `AmqpTopology::RetryDelayMs` apart. The attempts so far
 * are counted from the broker's `x-death` header, which tallies a message's rejections per queue.
 */
final readonly class RetryPolicy
{
	public const int MaxAttempts = 5;

	/**
	 * The attempts made before this delivery: the times the message was rejected from `$queue`.
	 *
	 * @param array<string, mixed> $headers the message's application headers
	 */
	public static function previousAttempts(array $headers, QueueEnum $queue): int
	{
		$deaths = $headers['x-death'] ?? null;
		if (!is_array($deaths)) {
			return 0;
		}
		foreach ($deaths as $death) {
			if (!is_array($death) || ($death['queue'] ?? null) !== $queue->value || ($death['reason'] ?? null) !== 'rejected') {
				continue;
			}
			$count = $death['count'] ?? 0;

			return is_int($count) ? $count : 0;
		}

		return 0;
	}

	/**
	 * Whether a delivery that failed is tried again.
	 *
	 * @param array<string, mixed> $headers
	 */
	public static function retries(array $headers, QueueEnum $queue): bool
	{
		return self::previousAttempts($headers, $queue) + 1 < self::MaxAttempts;
	}
}
