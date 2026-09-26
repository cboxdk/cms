<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The id of a principal: the actor or the ingestion source a command runs for (PRD 5.5, 6.1).
 * Together with its PrincipalKind it scopes an idempotency key.
 *
 * The value is opaque: 1 to MAX_LENGTH visible ASCII characters (0x21 to 0x7E), compared exactly.
 * The id of a user, a token, an agent or a source, such as "user:42" or "feed_reuters", fits.
 */
#[Experimental]
final readonly class PrincipalId
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidPrincipalId::malformed($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
