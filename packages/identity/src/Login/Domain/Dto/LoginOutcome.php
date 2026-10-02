<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Identity\Login\Domain\LoginField;
use Cbox\Cms\Identity\Sessions\Domain\Dto\NewSession;
use LogicException;

/**
 * How a local login ended (PRD 5.16): the session it issued, or the catalog code it was refused
 * with and the fields of the form the refusal is about, none when it is about the login as a whole.
 *
 * A refusal is one of three: validation_required for a field left empty, login_rate_limited when
 * the throttle refused the attempt, and login_rejected for every other refusal, an unknown email, a
 * wrong password and a login the policy refused alike, so the answer never tells whether an
 * account exists.
 */
#[Internal]
final readonly class LoginOutcome
{
    /**
     * @param  list<LoginField>  $fields
     */
    private function __construct(
        public ?NewSession $session,
        public ?ErrorCode $refusal,
        public array $fields,
    ) {}

    public static function loggedIn(NewSession $session): self
    {
        return new self($session, null, []);
    }

    /**
     * @throws LogicException when the code is not one a login is refused with
     */
    public static function refused(ErrorCode $code, LoginField ...$fields): self
    {
        if (! in_array($code, [ErrorCode::ValidationRequired, ErrorCode::LoginRateLimited, ErrorCode::LoginRejected], true)) {
            throw new LogicException(sprintf('A local login is not refused with %s.', $code->value));
        }

        return new self(null, $code, array_values($fields));
    }
}
