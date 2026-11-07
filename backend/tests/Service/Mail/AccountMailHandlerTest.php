<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Mail;

use DateTimeImmutable;
use Izbik\Model\Entity\EmailVerificationToken;
use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Model\Entity\PasswordResetToken;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\EmailVerificationTokenRepository;
use Izbik\Model\Repository\PasswordResetTokenRepository;
use Izbik\Model\Repository\UserRepository;
use Izbik\Service\Mail\AccountMailHandler;
use Izbik\Service\Mail\AccountMailJob;
use Izbik\Service\Mail\AccountMailKindEnum;
use Izbik\Service\Queue\PermanentJobFailure;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Component\Mime\Address;
use const JSON_THROW_ON_ERROR;

#[CoversClass(AccountMailHandler::class)]
#[CoversClass(AccountMailJob::class)]
final class AccountMailHandlerTest extends IntegrationTestCase
{
	public function testSendsTheMailInTheLanguageTheAccountHasNow(): void
	{
		$user = Fixture::createUser('jane@example.com', 'Jane');
		$payload = self::payload(self::job($user, '+1 hour'));
		$user->locale = LocaleEnum::Cs;
		$this->users()->persist($user);

		$this->handler()->handle($payload);

		$sent = AppHarness::mailer()->sent;
		self::assertCount(1, $sent);
		self::assertSame('Obnovení hesla k Izbiku', $sent[0]->getSubject());
		self::assertSame(['jane@example.com'], array_map(static fn (Address $to): string => $to->getAddress(), $sent[0]->getTo()));
		self::assertSame('no-reply@izbik.test', $sent[0]->getFrom()[0]->getAddress());
		self::assertStringContainsString('https://izbik.test/reset-password?token=abc', (string) $sent[0]->getTextBody());
		self::assertStringContainsString('60', (string) $sent[0]->getTextBody());
	}

	public function testDropsAMailWhoseLinkHasExpired(): void
	{
		$user = Fixture::createUser('jane@example.com');

		$this->handler()->handle(self::payload(self::job($user, '-1 minute')));

		self::assertSame([], AppHarness::mailer()->sent);
	}

	public function testDropsAMailToAnAddressTheAccountNoLongerHas(): void
	{
		$user = Fixture::createUser('jane@example.com');
		$payload = self::payload(self::job($user, '+1 hour'));
		$user->email = 'jane.new@example.com';
		$this->users()->persist($user);

		$this->handler()->handle($payload);

		self::assertSame([], AppHarness::mailer()->sent);
	}

	public function testDropsAMailOfADeletedAccount(): void
	{
		$user = Fixture::createUser('jane@example.com');
		$payload = self::payload(self::job($user, '+1 hour'));
		$this->users()->delete($user);

		$this->handler()->handle($payload);

		self::assertSame([], AppHarness::mailer()->sent);
	}

	public function testDropsAResetMailWhoseLinkHasBeenUsedOrVoided(): void
	{
		$user = Fixture::createUser('jane@example.com');
		$job = self::job($user, '+1 hour');
		$token = $this->resetTokens()->findOneByTokenHash($job->tokenHash);
		self::assertNotNull($token);
		$token->usedAt = new DateTimeImmutable();
		$this->resetTokens()->persist($token);

		$this->handler()->handle(self::payload($job));

		self::assertSame([], AppHarness::mailer()->sent);
	}

	public function testDropsAVerificationMailOfAnAccountVerifiedMeanwhile(): void
	{
		$user = Fixture::createUser('jane@example.com');
		$user->emailVerified = false;
		$this->users()->persist($user);
		$job = self::verificationJob($user);
		$user->emailVerified = true;
		$this->users()->persist($user);

		$this->handler()->handle(self::payload($job));

		self::assertSame([], AppHarness::mailer()->sent);
	}

	public function testSendsAVerificationMailOfAnUnverifiedAccount(): void
	{
		$user = Fixture::createUser('jane@example.com');
		$user->emailVerified = false;
		$this->users()->persist($user);

		$this->handler()->handle(self::payload(self::verificationJob($user)));

		self::assertCount(1, AppHarness::mailer()->sent);
	}

	public function testAnUnreadableJobFailsForGood(): void
	{
		$this->expectException(PermanentJobFailure::class);

		$this->handler()->handle('{"kind":"newsletter"}');
	}

	public function testAFailedSendThrowsForARetry(): void
	{
		$user = Fixture::createUser('jane@example.com');
		AppHarness::mailer()->failNext = true;

		try {
			$this->handler()->handle(self::payload(self::job($user, '+1 hour')));
			self::fail('The send should have failed.');
		} catch (RuntimeException $e) {
			self::assertNotInstanceOf(PermanentJobFailure::class, $e);
		}
	}

	/** A reset mail with its token row, as `PasswordResetService` leaves them. */
	private static function job(User $user, string $expiresIn): AccountMailJob
	{
		$now = new DateTimeImmutable();
		$token = new PasswordResetToken(user: $user, tokenHash: bin2hex(random_bytes(16)), expiresAt: $now->modify('+1 hour'));
		$token->createdAt = $now;
		$token->updatedAt = $now;
		$repository = AppHarness::container()->get(PasswordResetTokenRepository::class);
		assert($repository instanceof PasswordResetTokenRepository);
		$repository->persist($token);

		return new AccountMailJob(
			kind: AccountMailKindEnum::PasswordReset,
			userId: $user->id,
			email: $user->email,
			url: 'https://izbik.test/reset-password?token=abc',
			tokenHash: $token->tokenHash,
			expiresAt: new DateTimeImmutable($expiresIn),
			params: ['minutes' => 60],
		);
	}

	private static function verificationJob(User $user): AccountMailJob
	{
		$now = new DateTimeImmutable();
		$token = new EmailVerificationToken(
			user: $user,
			tokenHash: bin2hex(random_bytes(16)),
			email: $user->email,
			expiresAt: $now->modify('+1 day'),
		);
		$token->createdAt = $now;
		$token->updatedAt = $now;
		$repository = AppHarness::container()->get(EmailVerificationTokenRepository::class);
		assert($repository instanceof EmailVerificationTokenRepository);
		$repository->persist($token);

		return new AccountMailJob(
			kind: AccountMailKindEnum::EmailVerification,
			userId: $user->id,
			email: $user->email,
			url: 'https://izbik.test/verify-email?token=abc',
			tokenHash: $token->tokenHash,
			expiresAt: $token->expiresAt,
			params: ['hours' => 24],
		);
	}

	private static function payload(AccountMailJob $job): string
	{
		return json_encode($job, JSON_THROW_ON_ERROR);
	}

	private function handler(): AccountMailHandler
	{
		$handler = AppHarness::container()->get(AccountMailHandler::class);
		assert($handler instanceof AccountMailHandler);

		return $handler;
	}

	private function resetTokens(): PasswordResetTokenRepository
	{
		$tokens = AppHarness::container()->get(PasswordResetTokenRepository::class);
		assert($tokens instanceof PasswordResetTokenRepository);

		return $tokens;
	}

	private function users(): UserRepository
	{
		$users = AppHarness::container()->get(UserRepository::class);
		assert($users instanceof UserRepository);

		return $users;
	}
}
