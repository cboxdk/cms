<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * A group the identity provider says the person is in (PRD 5.16), as its groups claim names it: an
 * id such as an Entra object id, or a name. 1 to MAX_LENGTH characters of UTF-8 without control
 * characters, compared exactly. The kernel maps groups to roles only through the group-to-role
 * mapping; a group is never a grant.
 */
#[Experimental]
final readonly class IdpGroup
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[^\p{Cc}]{1,255}\z/u';

    /**
     * @throws InvalidIdentity when the value is not in the form
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::loginValue('group', '1 to 255 characters of UTF-8 without control characters');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
