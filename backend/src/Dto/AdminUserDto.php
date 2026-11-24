<?php

declare(strict_types=1);

namespace Izbik\Dto;

use JsonSerializable;
use function get_object_vars;

/** A user as the admins' user list shows them: the account and how many projects it owns. */
final readonly class AdminUserDto implements JsonSerializable
{
	public function __construct(public UserDto $user, public int $projectCount)
	{
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array
	{
		/** @var array<string, mixed> $fields */
		$fields = get_object_vars($this->user);

		return [...$fields, 'projectCount' => $this->projectCount];
	}
}
