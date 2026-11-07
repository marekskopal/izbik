<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

use DateTimeImmutable;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\EmailVerificationTokenRepository;
use Izbik\Model\Repository\PasswordResetTokenRepository;
use Izbik\Model\Repository\UserRepository;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\Queue\JobHandlerInterface;
use Izbik\Service\Queue\PermanentJobFailure;
use Izbik\Service\Translator\Translator;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Throwable;

/**
 * Sends an `AccountMailJob` in the account's language. A job that has become pointless sends nothing: the account is
 * gone, its address is no longer the one the link was for (an admin changed it), or the link has expired while the mail
 * waited for retries, or it has been used or voided meanwhile (a newer reset link used, the address verified). A failed
 * send throws, so the job is tried again.
 */
final readonly class AccountMailHandler implements JobHandlerInterface
{
	public function __construct(
		private UserRepository $userRepository,
		private PasswordResetTokenRepository $passwordResetTokens,
		private EmailVerificationTokenRepository $emailVerificationTokens,
		private Translator $translator,
		private MailerInterface $mailer,
		private MailConfig $mailConfig,
		private LoggerInterface $logger,
	) {
	}

	#[Override]
	public function handle(string $payload): void
	{
		$job = self::read($payload);

		$user = $this->userRepository->findById($job->userId);
		if ($user === null || $user->email !== $job->email) {
			$this->logger->info('Account mail dropped: the account or its address has changed.', ['kind' => $job->kind->value]);

			return;
		}
		if ($job->expiresAt <= new DateTimeImmutable() || !$this->linkWorks($job, $user)) {
			$this->logger->info('Account mail dropped: its link has expired or been used.', ['kind' => $job->kind->value]);

			return;
		}

		$this->mailer->send(AccountEmail::create(
			translator: $this->translator,
			locale: $user->locale,
			recipientEmail: $user->email,
			userName: $user->name,
			kind: $job->kind->value,
			url: $job->url,
			params: $job->params,
		)->from($this->mailConfig->from));
	}

	private function linkWorks(AccountMailJob $job, User $user): bool
	{
		if ($job->kind === AccountMailKindEnum::EmailVerification && $user->emailVerified) {
			return false;
		}
		$token = match ($job->kind) {
			AccountMailKindEnum::PasswordReset => $this->passwordResetTokens->findOneByTokenHash($job->tokenHash),
			AccountMailKindEnum::EmailVerification => $this->emailVerificationTokens->findOneByTokenHash($job->tokenHash),
		};

		return $token !== null && $token->usedAt === null && $token->user->id === $job->userId;
	}

	private static function read(string $payload): AccountMailJob
	{
		try {
			return AccountMailJob::fromArray(JsonDecoder::decodeObject($payload));
		} catch (Throwable $e) {
			throw new PermanentJobFailure('Unreadable account mail job: ' . $e->getMessage(), 0, $e);
		}
	}
}
