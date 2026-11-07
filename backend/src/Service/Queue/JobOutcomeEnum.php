<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

/** What the consumer does with a delivered job (`QueueConsumer::run()`). */
enum JobOutcomeEnum
{
	/** Done (or pointless now): acknowledged. */
	case Done;

	/** Failed, tried again after `AmqpTopology::RetryDelayMs`: rejected into the retry queue. */
	case Retry;

	/** Failed for good: published to the failed queue and acknowledged. */
	case Park;
}
