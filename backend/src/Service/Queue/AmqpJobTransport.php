<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;

/**
 * Publishes jobs to RabbitMQ as persistent messages, for the consumer (`queue:consume`), and waits for the broker to
 * confirm each, so a message it did not take is an error (logged by `JobQueue`) rather than lost unseen. The connection
 * opens with the first job and stays open in the long-lived worker; one that has broken is opened again once.
 */
final class AmqpJobTransport implements JobTransportInterface
{
	private ?AbstractConnection $connection = null;

	private const int ConfirmTimeoutSeconds = 5;

	private ?AMQPChannel $channel = null;

	/** @var array<string, true> */
	private array $declared = [];

	public function __construct(private readonly AmqpConnector $connector)
	{
	}

	public function send(QueueEnum $queue, string $payload): void
	{
		try {
			$this->publish($queue, $payload);
		} catch (Throwable) {
			$this->close();
			$this->publish($queue, $payload);
		}
	}

	private function publish(QueueEnum $queue, string $payload): void
	{
		$channel = $this->channel();
		if (!isset($this->declared[$queue->value])) {
			AmqpTopology::declare($channel, $queue);
			$this->declared[$queue->value] = true;
		}
		$channel->basic_publish(
			new AMQPMessage($payload, ['content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]),
			'',
			$queue->value,
		);
		$channel->wait_for_pending_acks(self::ConfirmTimeoutSeconds);
	}

	private function channel(): AMQPChannel
	{
		if ($this->channel === null || !$this->channel->is_open()) {
			$this->connection = $this->connector->forPublishing();
			$channel = $this->connection->channel();
			$channel->set_nack_handler(static function (): void {
				throw new RuntimeException('The broker refused a background job.');
			});
			$channel->confirm_select();
			$this->channel = $channel;
		}

		return $this->channel;
	}

	private function close(): void
	{
		try {
			$this->connection?->close();
		} catch (Throwable) {
			// Broken already.
		}

		$this->connection = null;
		$this->channel = null;
		$this->declared = [];
	}
}
