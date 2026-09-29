<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The version of an aggregate: 1 when it is created, and one higher with every changeset that
 * changes it. A command commits only when every aggregate it read still has the version it was
 * read at (PRD 6.1, 6.2 phase 7, 6.8).
 */
#[Experimental]
final readonly class AggregateVersion
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw InvalidPipelineValue::version($value);
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
