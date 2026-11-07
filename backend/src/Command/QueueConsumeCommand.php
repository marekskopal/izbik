<?php

declare(strict_types=1);

namespace Izbik\Command;

use Izbik\App\ApplicationFactory;
use Izbik\Service\Queue\QueueConfig;
use Izbik\Service\Queue\QueueConsumer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use const PHP_EOL;
use const STDERR;

/**
 * The background job consumer, run by supervisord next to FrankenPHP. Without a broker (`RABBITMQ_HOST` empty) it exits
 * at once with success, which supervisord leaves at that. When anything fails (the start, the broker unreachable, the
 * connection broken) it waits a moment and fails, and supervisord starts it again.
 */
final class QueueConsumeCommand extends AbstractCommand
{
	private const int RestartDelaySeconds = 5;

	/** How long an SMTP send may wait, well within the consumer's heartbeat (`AmqpConnector`). */
	private const int SocketTimeoutSeconds = 20;

	protected function configure(): void
	{
		$this->setName('queue:consume')
			->setDescription('Run the background jobs from RabbitMQ (account mail).');
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		try {
			$container = ApplicationFactory::create()->container;
			$config = $container->get(QueueConfig::class);
			assert($config instanceof QueueConfig);
			if (!$config->isEnabled()) {
				$output->writeln('No RABBITMQ_HOST: background jobs run in the web worker, the consumer is not needed.');

				return self::SUCCESS;
			}

			ini_set('default_socket_timeout', (string) self::SocketTimeoutSeconds);
			$consumer = $container->get(QueueConsumer::class);
			assert($consumer instanceof QueueConsumer);
			$consumer->run();
		} catch (Throwable $e) {
			// Stderr, as the logger may be what failed to start.
			fwrite(STDERR, 'The background job consumer stopped: ' . $e->getMessage() . PHP_EOL);
			sleep(self::RestartDelaySeconds);

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
