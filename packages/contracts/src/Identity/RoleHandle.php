<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The handle of a role (PRD 5.10), unique among the roles of an installation, such as editor or
 * news_desk: a lowercase letter followed by up to 62 lowercase letters, digits and underscores, as
 * PATTERN says and the roles table checks. It names the role in the panel and in the access
 * report; the kernel decides by the role's id.
 */
#[Experimental]
final readonly class RoleHandle
{
    /** The longest role handle, in characters. */
    public const int MAX_LENGTH = 63;

    /** The form of a role handle, as the JSON Schema of role.create states it. */
    public const string PATTERN = '^[a-z][a-z0-9_]{0,62}$';

    public function __construct(public string $value)
    {
        if (preg_match('/'.self::PATTERN.'/D', $value) !== 1) {
            throw InvalidIdentity::roleHandle();
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
