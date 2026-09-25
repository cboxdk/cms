<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The idempotency key a caller sends in the envelope (PRD 6.1). A key is scoped per actor or
 * source and command type (IdempotencyScope) and stored with a hash of the command's content
 * (ContentHash).
 *
 * The key is opaque: 1 to MAX_LENGTH visible ASCII characters (0x21 to 0x7E), compared exactly.
 * A UUID, a ULID or an ingestion key such as "source:object:version" fits.
 */
#[Experimental]
final readonly class IdempotencyKey
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdempotencyValue::key($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
