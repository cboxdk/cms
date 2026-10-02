<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * A password reset link just issued (PRD 5.16): the actor and the login of the account it resets,
 * the link, which carries the token and is shown only here, and when it expires. var_dump() and a
 * stack trace never show the login or the link.
 */
#[Internal]
final readonly class IssuedResetLink
{
    public function __construct(
        public ActorId $actor,
        #[SensitiveParameter] public LoginIdentifier $login,
        #[SensitiveParameter] private string $link,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * The link with the token. Never log or store it.
     */
    public function link(): string
    {
        return $this->link;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['actor' => $this->actor->toString(), 'login' => '[personal]', 'link' => '[secret]', 'expiresAt' => $this->expiresAt->format(DATE_ATOM)];
    }
}
