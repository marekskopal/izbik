<?php

declare(strict_types=1);

namespace Izbik\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** Stands in for the SMTP mailer in tests and keeps what was sent; `$failNext` fails the next send as a dead server would. */
final class RecordingMailer implements MailerInterface
{
	/** @var list<Email> */
	public array $sent = [];

	public bool $failNext = false;

	public function send(RawMessage $message, ?Envelope $envelope = null): void
	{
		if ($this->failNext) {
			$this->failNext = false;

			throw new TransportException('Connection refused.');
		}
		assert($message instanceof Email);
		$this->sent[] = $message;
	}
}
