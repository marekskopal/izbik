<?php

declare(strict_types=1);

namespace Izbik\Service\Queue;

/**
 * The background job queues (`JobQueue`). Each one has a retry queue its failed messages wait in before they come back
 * (`AmqpTopology`) and a failed queue, without a consumer, keeping those that ran out of attempts for inspection.
 */
enum QueueEnum: string
{
	/** The mails about a user's account (`AccountMailJob`). */
	case AccountMail = 'izbik.account-mail';

	public function retryQueue(): string
	{
		return $this->value . '.retry';
	}

	public function failedQueue(): string
	{
		return $this->value . '.failed';
	}
}
