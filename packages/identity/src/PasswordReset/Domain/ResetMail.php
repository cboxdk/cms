<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Contracts\Egress\OutboundMail;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use SensitiveParameter;

/**
 * The mail that carries a password reset link (PRD 5.16), in plain text, counted by the mail
 * gateway under the host class HOST_CLASS. It says what the link is for, for how long it works,
 * that it works once and what to do when the person did not ask for it.
 */
#[Internal]
final readonly class ResetMail
{
    public const string HOST_CLASS = 'password_reset';

    public const string SUBJECT = 'Reset your password for Cbox CMS';

    private function __construct() {}

    public static function to(EmailAddress $recipient, #[SensitiveParameter] string $link, int $minutes): OutboundMail
    {
        return new OutboundMail(new HostClass(self::HOST_CLASS), $recipient, self::SUBJECT, implode("\n", [
            'Someone asked to reset the password of your account in Cbox CMS.',
            '',
            sprintf('To choose a new password, open this link within %d minutes:', $minutes),
            '',
            $link,
            '',
            'The link works once. If you did not ask for it, you can ignore this mail: your password stays as it is.',
            '',
        ]));
    }
}
