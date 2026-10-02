<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use LogicException;

/**
 * The identity module refuses to boot a process that serves HTTP with a session cookie that is not
 * safe in its environment (PRD 5.16): outside local and testing, the session cookie is Secure,
 * named with the __Host- prefix and never SameSite=None, so it travels only over HTTPS, only to
 * this host, and never with a request another site starts in the background.
 */
#[Internal]
final class InsecureSessionCookie extends LogicException
{
    public const string CODE = 'session_cookie_insecure';

    public static function in(string $environment, SessionCookie $cookie): self
    {
        return new self(sprintf(
            '[%s] The session cookie of the environment %s is not safe outside local and testing: %s. Set cbox-cms.identity.session.cookie.%s to the name %s with secure true and same_site lax or strict, or remove the entry so the default applies; cms:doctor\'s identity.session_cookie says the same.',
            self::CODE,
            $environment,
            implode('; ', $cookie->insecurities()),
            $environment,
            SessionCookie::PRODUCTION_NAME,
        ));
    }
}
