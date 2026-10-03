<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use LogicException;

/**
 * Whether a reset link was issued for a login (PRD 5.16): the link, or the catalog code of why not,
 * local_account_missing for a login without a local account, or the login policy's code for an
 * account whose actor the policy would not let log in by password reset (REFUSALS: actor_not_active,
 * login_class_not_allowed, login_connection_not_allowed, login_method_not_allowed,
 * login_local_disabled or login_authoritative_link). Only an operator's command says which; the panel's page answers every
 * request the same.
 */
#[Internal]
final readonly class ResetLinkOutcome
{
    /**
     * The codes a link is refused with.
     *
     * @var list<ErrorCode>
     */
    public const array REFUSALS = [
        ErrorCode::LocalAccountMissing,
        ErrorCode::ActorNotActive,
        ErrorCode::LoginClassNotAllowed,
        ErrorCode::LoginConnectionNotAllowed,
        ErrorCode::LoginMethodNotAllowed,
        ErrorCode::LoginLocalDisabled,
        ErrorCode::LoginAuthoritativeLink,
    ];

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
        if (! in_array($code, self::REFUSALS, true)) {
            throw new LogicException(sprintf('A reset link is not refused with %s.', $code->value));
        }

        return new self(null, $code);
    }
}
