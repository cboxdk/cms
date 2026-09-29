<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The key of a rendered fragment in the FragmentStore (PRD 8.12, 9.3). The renderer builds it
 * from what the output depends on, such as the host, the path, the known parameters and the
 * audience segment (PRD 8.10 point 8); the store treats it as opaque.
 *
 * A key is 1 to MAX_LENGTH visible ASCII characters (0x21 to 0x7E), compared exactly. A renderer
 * whose inputs are longer hashes them.
 */
#[Experimental]
final readonly class FragmentKey
{
    public const int MAX_LENGTH = 512;

    private const string PATTERN = '/\A[\x21-\x7E]{1,512}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidCacheValue::fragmentKey($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
