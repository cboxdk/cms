<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The number of a revision within one variant of an entry: 1 for the first, one higher for each
 * next (PRD 5.3, 5.4). Outside the database a revision is named by (entry, variant, number), never
 * by an internal sequence.
 */
#[Experimental]
final readonly class RevisionNumber
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw InvalidContentValue::revisionNumber($value);
        }
    }

    public static function first(): self
    {
        return new self(1);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
