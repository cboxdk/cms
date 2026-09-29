<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The kind of aggregate an event is about, such as entry, variant, placement or actor (PRD 7.2):
 * snake_case, at most MAX_LENGTH characters.
 */
#[Experimental]
final readonly class AggregateType
{
    public const int MAX_LENGTH = 63;

    private const string PATTERN = '/\A[a-z][a-z0-9_]*\z/';

    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidEvent::aggregateType();
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
