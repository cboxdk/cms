<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The setting of the session cookie, `cbox-cms.identity.session.cookie`, is invalid for an
 * environment (PRD 5.16), so no session cookie can be set. The message names the key and what it
 * must be, never the value.
 */
#[Internal]
final class InvalidSessionCookie extends InvalidArgumentException
{
    public const string CODE = 'session_cookie_invalid';

    public static function key(string $key, string $expected): self
    {
        return new self(sprintf('[%s] cbox-cms.identity.session.cookie%s must be %s.', self::CODE, $key === '' ? '' : '.'.$key, $expected));
    }

    public function reason(): string
    {
        return substr($this->getMessage(), strlen(self::CODE) + 3);
    }
}
