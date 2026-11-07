<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

use Closure;
use Izbik\Service\Dbal\DbContext;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Consumes every `QueueEnum` queue until the connection breaks (supervisord then starts it again). Each job runs by its
 * handler; a failed one is retried `RetryPolicy::MaxAttempts` times in all, then parked in the failed queue.
 */
final readonly class QueueConsumer
{
	public function __construct(
		private AmqpConnector $connector,
		private QueueConfig $config,
		private JobHandlers $handlers,
		private DbContext $dbContext,
		private LoggerInterface $logger,
	) {
	}

	public function run(): void
	{
		$connection = $this->connector->forConsuming();
		$channel = $connection->channel();
		$channel->basic_qos(0, $this->config->prefetch, false);
		foreach (QueueEnum::cases() as $queue) {
			AmqpTopology::declare($channel, $queue);
			$channel->basic_consume(
				queue: $queue->value,
				callback: function (AMQPMessage $message) use ($channel, $queue): void {
					$this->deliver($channel, $queue, $message);
				},
			);
		}
		$this->logger->info('Consuming the background job queues.');

		try {
			$channel->consume();
		} finally {
			$channel->close();
			$connection->close();
		}
	}

	/**
	 * Runs a job and says what becomes of it. The handler is resolved inside, so one that cannot be built counts as a
	 * failed attempt too.
	 *
	 * @param Closure(): JobHandlerInterface $handler
	 * @param array<string, mixed> $headers the message's application headers
	 */
	public function outcome(Closure $handler, QueueEnum $queue, string $payload, array $headers): JobOutcomeEnum
	{
		try {
			$handler()->handle($payload);

			return JobOutcomeEnum::Done;
		} catch (PermanentJobFailure $e) {
			$this->logger->error('Background job failed for good: ' . $e->getMessage(), ['queue' => $queue->value, 'exception' => $e]);

			return JobOutcomeEnum::Park;
		} catch (Throwable $e) {
			$attempt = RetryPolicy::previousAttempts($headers, $queue) + 1;
			$retries = RetryPolicy::retries($headers, $queue);
			$this->logger->error(
				sprintf('Background job failed (attempt %d of %d): %s', $attempt, RetryPolicy::MaxAttempts, $e->getMessage()),
				['queue' => $queue->value, 'exception' => $e],
			);

			return $retries ? JobOutcomeEnum::Retry : JobOutcomeEnum::Park;
		} finally {
			$this->dbContext->resetOrmState();
			gc_collect_cycles();
		}
	}

	private function deliver(AMQPChannel $channel, QueueEnum $queue, AMQPMessage $message): void
	{
		$headers = $message->has('application_headers') ? $message->get('application_headers') : null;
		$headerData = $headers instanceof AMQPTable ? $headers->getNativeData() : [];
		/** @var array<string, mixed> $headerData */

		switch ($this->outcome(fn (): JobHandlerInterface => $this->handlers->for($queue), $queue, $message->getBody(), $headerData)) {
			case JobOutcomeEnum::Done:
				$message->ack();

				break;
			case JobOutcomeEnum::Retry:
				$message->nack(false, false);

				break;
			case JobOutcomeEnum::Park:
				$channel->basic_publish(
					new AMQPMessage($message->getBody(), [
						'content_type' => 'application/json',
						'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
					]),
					'',
					$queue->failedQueue(),
				);
				$message->ack();

				break;
		}
	}
}
