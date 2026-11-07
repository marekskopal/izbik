<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

use SensitiveParameter;

/**
 * The RabbitMQ broker the background jobs go through (`RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`,
 * `RABBITMQ_PASSWORD`; `BACKEND_AMQP_CONSUMER_PREFETCH` messages a consumer takes at once). Without `RABBITMQ_HOST` there
 * is no broker: the jobs run in the web worker after the response (`InlineJobTransport`), and the consumer exits.
 */
final readonly class QueueConfig
{
	private const int DefaultPort = 5672;
	private const int DefaultPrefetch = 10;

	public function __construct(
		public string $host,
		public int $port = self::DefaultPort,
		public string $user = '',
		#[SensitiveParameter] public string $password = '',
		public int $prefetch = self::DefaultPrefetch,
	) {
	}

	public static function fromEnv(): self
	{
		$port = (int) getenv('RABBITMQ_PORT');
		$prefetch = (int) getenv('BACKEND_AMQP_CONSUMER_PREFETCH');

		return new self(
			host: trim((string) getenv('RABBITMQ_HOST')),
			port: $port > 0 ? $port : self::DefaultPort,
			user: (string) getenv('RABBITMQ_USER'),
			password: (string) getenv('RABBITMQ_PASSWORD'),
			prefetch: $prefetch > 0 ? $prefetch : self::DefaultPrefetch,
		);
	}

	public function isEnabled(): bool
	{
		return $this->host !== '';
	}
}
