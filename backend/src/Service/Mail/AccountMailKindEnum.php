<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

/** The mails about a user's account, by their texts `mail.<kind>.*` (`AccountEmail`). */
enum AccountMailKindEnum: string
{
	case PasswordReset = 'passwordReset';
	case EmailVerification = 'emailVerification';
}
