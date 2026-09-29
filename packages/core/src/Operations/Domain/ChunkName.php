<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name of one chunk of a chunked action, such as "rows-000001". It is the step's name in the
 * operation, so the operation's progress lists the chunks by these names. 1 to MAX_LENGTH visible
 * ASCII characters, compared exactly.
 */
#[Experimental]
final readonly class ChunkName
{
    public const int MAX_LENGTH = 200;

    /**
     * @throws InvalidOperation when the name is empty, longer than MAX_LENGTH or not visible ASCII
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw InvalidOperation::chunk($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
