<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

use DateTimeImmutable;
use DateTimeInterface;
use Izbik\Dto\ArrayFactoryInterface;
use Izbik\Service\Request\BodyReader;
use JsonSerializable;
use Override;

/**
 * A mail about a user's account (`AccountMailHandler`). It names the user rather than carrying the mail, so the mail is
 * written in the language the account has when it goes out, and to the address it was meant for (`$email`) only. The
 * `$url` holds the link's one-time token, which waits in the broker until the mail is sent (the database keeps only its
 * hash, `$tokenHash`, by which the handler sees whether the link still works); `$expiresAt` is when the link dies.
 */
final readonly class AccountMailJob implements ArrayFactoryInterface, JsonSerializable
{
	/** @param array<string, string|int> $params the footnote's placeholders */
	public function __construct(
		public AccountMailKindEnum $kind,
		public int $userId,
		public string $email,
		public string $url,
		public string $tokenHash,
		public DateTimeImmutable $expiresAt,
		public array $params = [],
	) {
	}

	/** @param array<string, mixed> $data */
	#[Override]
	public static function fromArray(array $data): static
	{
		$params = [];
		foreach (BodyReader::object($data, 'params') as $key => $value) {
			if (is_string($value) || is_int($value)) {
				$params[$key] = $value;
			}
		}

		return new self(
			kind: AccountMailKindEnum::from(BodyReader::string($data, 'kind')),
			userId: BodyReader::int($data, 'userId'),
			email: BodyReader::string($data, 'email'),
			url: BodyReader::string($data, 'url'),
			tokenHash: BodyReader::string($data, 'tokenHash'),
			expiresAt: new DateTimeImmutable(BodyReader::string($data, 'expiresAt')),
			params: $params,
		);
	}

	/** @return array<string, mixed> */
	#[Override]
	public function jsonSerialize(): array
	{
		return [
			'kind' => $this->kind->value,
			'userId' => $this->userId,
			'email' => $this->email,
			'url' => $this->url,
			'tokenHash' => $this->tokenHash,
			'expiresAt' => $this->expiresAt->format(DateTimeInterface::ATOM),
			'params' => $this->params,
		];
	}
}
