<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Opens a connection to the broker of `QueueConfig`. The web worker's publishing connection has no heartbeat: an idle
 * worker would send none, and the broker would close the connection. The consumer's heartbeat is long enough for a
 * slow send to finish between two of them (the consumer holds sockets to 20 s, `QueueConsumeCommand`), as it cannot
 * answer one while a job runs.
 */
final readonly class AmqpConnector
{
	/** Seconds. */
	private const int ConsumerHeartbeat = 120;
	private const float PublisherTimeout = 10.0;

	public function __construct(private QueueConfig $config)
	{
	}

	public function forPublishing(): AMQPStreamConnection
	{
		return $this->connect(read_write_timeout: self::PublisherTimeout, heartbeat: 0);
	}

	public function forConsuming(): AMQPStreamConnection
	{
		return $this->connect(read_write_timeout: self::ConsumerHeartbeat * 2, heartbeat: self::ConsumerHeartbeat);
	}

	private function connect(float $read_write_timeout, int $heartbeat): AMQPStreamConnection
	{
		return new AMQPStreamConnection(
			host: $this->config->host,
			port: $this->config->port,
			user: $this->config->user,
			password: $this->config->password,
			read_write_timeout: $read_write_timeout,
			keepalive: true,
			heartbeat: $heartbeat,
		);
	}
}
