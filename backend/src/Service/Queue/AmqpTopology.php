<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * The queues of one `QueueEnum`, on the default exchange: a rejected message of the queue dead-letters into its retry
 * queue, waits there `RetryDelayMs` and dead-letters back, a delayed retry without a broker plugin. The failed queue has
 * no consumer and keeps a message `FailedTtlMs` (a week; its links have long expired by then), `FailedMaxLength` at
 * most. Declaring is idempotent, so the publisher and the consumer both do it; changing a queue's arguments needs the
 * queue deleted first.
 */
final readonly class AmqpTopology
{
	public const int RetryDelayMs = 60000;
	private const int FailedTtlMs = 604800000;
	private const int FailedMaxLength = 10000;

	public static function declare(AMQPChannel $channel, QueueEnum $queue): void
	{
		$channel->queue_declare($queue->value, false, true, false, false, false, new AMQPTable([
			'x-dead-letter-exchange' => '',
			'x-dead-letter-routing-key' => $queue->retryQueue(),
		]));
		$channel->queue_declare($queue->retryQueue(), false, true, false, false, false, new AMQPTable([
			'x-message-ttl' => self::RetryDelayMs,
			'x-dead-letter-exchange' => '',
			'x-dead-letter-routing-key' => $queue->value,
		]));
		$channel->queue_declare($queue->failedQueue(), false, true, false, false, false, new AMQPTable([
			'x-message-ttl' => self::FailedTtlMs,
			'x-max-length' => self::FailedMaxLength,
		]));
	}
}
