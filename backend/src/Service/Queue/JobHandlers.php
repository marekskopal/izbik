<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

use Izbik\Service\Mail\AccountMailHandler;
use Psr\Container\ContainerInterface;

/** The handler of each queue, shared by the consumer and `InlineJobTransport`. */
final readonly class JobHandlers
{
	public function __construct(private ContainerInterface $container)
	{
	}

	public function for(QueueEnum $queue): JobHandlerInterface
	{
		$class = match ($queue) {
			QueueEnum::AccountMail => AccountMailHandler::class,
		};
		$handler = $this->container->get($class);
		assert($handler instanceof JobHandlerInterface);

		return $handler;
	}
}
