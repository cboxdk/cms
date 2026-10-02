<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The identity provider's id of the session a login came from, the `sid` claim of OpenID Connect
 * (PRD 5.16): 1 to 255 visible ASCII characters, compared exactly. A back-channel logout names it,
 * and every session from that IdP session ends.
 */
#[Internal]
final readonly class IdpSessionId
{
    private const string PATTERN = '/\A[\x21-\x7e]{1,255}\z/';

    /**
     * @throws InvalidArgumentException when the value is not 1 to 255 visible ASCII characters
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('An IdP session id is 1 to 255 visible ASCII characters.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
