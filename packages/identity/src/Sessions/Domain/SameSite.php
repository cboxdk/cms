<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The SameSite attribute of the session cookie (RFC 6265bis): when a browser sends the cookie with
 * a request another site started. Lax sends it on a top-level navigation only, Strict never, and
 * None always, which needs Secure and is never safe for a session.
 */
#[Internal]
enum SameSite: string
{
    case Lax = 'lax';
    case Strict = 'strict';
    case None = 'none';

    /**
     * The value as the Set-Cookie header writes it.
     */
    public function attribute(): string
    {
        return ucfirst($this->value);
    }
}
