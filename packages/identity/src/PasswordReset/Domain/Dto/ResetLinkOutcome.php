<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use LogicException;

/**
 * Whether a reset link was issued for a login (PRD 5.16): the link, or the catalog code of why not,
 * local_account_missing for a login without a local account and actor_not_active for an account
 * whose actor is not active. Only an operator's command says which; the panel's page answers every
 * request the same.
 */
#[Internal]
final readonly class ResetLinkOutcome
{
    private function __construct(
        public ?IssuedResetLink $link,
        public ?ErrorCode $refusal,
    ) {}

    public static function issued(IssuedResetLink $link): self
    {
        return new self($link, null);
    }

    /**
     * @throws LogicException when the code is not one a link is refused with
     */
    public static function refused(ErrorCode $code): self
    {
        if (! in_array($code, [ErrorCode::LocalAccountMissing, ErrorCode::ActorNotActive], true)) {
            throw new LogicException(sprintf('A reset link is not refused with %s.', $code->value));
        }

        return new self(null, $code);
    }
}
