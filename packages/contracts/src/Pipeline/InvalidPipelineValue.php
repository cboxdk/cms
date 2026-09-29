<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * An aggregate version or a set of reads that breaks its invariants.
 */
#[Experimental]
final class InvalidPipelineValue extends InvalidArgumentException
{
    public static function version(int $value): self
    {
        return new self(sprintf('An aggregate version starts at 1, got %d.', $value));
    }

    public static function duplicateRead(string $aggregateKey): self
    {
        return new self(sprintf('The aggregate "%s" is read twice in one write.', $aggregateKey));
    }
}
