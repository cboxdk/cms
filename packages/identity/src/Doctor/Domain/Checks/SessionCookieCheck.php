<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Identity\Doctor\Domain\Probes\SessionCookieProbe;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\InvalidSessionCookie;
use Override;

/**
 * The session cookie of this environment can be set and is safe (PRD 5.16): outside local and
 * testing it is Secure, named with the __Host- prefix and never SameSite=None. The identity module
 * refuses to boot a process that serves HTTP with a cookie that fails here, and this check says
 * why, from a console process that still boots.
 */
#[Internal]
final readonly class SessionCookieCheck implements DoctorCheck
{
    public const string ID = 'identity.session_cookie';

    public const string CODE_INVALID = 'doctor_session_cookie_invalid';

    public const string CODE_INSECURE = 'doctor_session_cookie_insecure';

    public function __construct(private SessionCookieProbe $probe) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return true;
    }

    #[Override]
    public function requires(): array
    {
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        $environment = $this->probe->environment();

        try {
            $cookie = $this->probe->cookie();
        } catch (InvalidSessionCookie $invalid) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_INVALID,
                'The session cookie of this environment cannot be set.',
                $invalid->reason(),
                'Correct cbox-cms.identity.session.cookie as the cause says, then run cms:doctor again.',
            );
        }

        if (! $cookie->safeIn($environment)) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_INSECURE,
                sprintf('The session cookie of the environment %s is not safe outside local and testing, and processes that serve HTTP refuse to boot with it.', $environment),
                sprintf('The cookie %s: %s.', $cookie->name, implode('; ', $cookie->insecurities())),
                sprintf('Set cbox-cms.identity.session.cookie.%s to the name %s with secure true and same_site lax or strict, or remove the entry so the default applies.', $environment, SessionCookie::PRODUCTION_NAME),
            );
        }

        return CheckResult::pass($this->id(), true, 'The session cookie of this environment can be set, and is safe in it.');
    }
}
