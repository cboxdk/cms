<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * The mutations Pest made of one file and how many of them a test caught: a test failed, or the
 * run timed out, as Pest's score counts them.
 */
final readonly class MutationCount
{
    public function __construct(
        public int $mutations,
        public int $caught,
    ) {
        if ($mutations < 0 || $caught < 0 || $caught > $mutations) {
            throw new InvalidArgumentException("{$caught} caught of {$mutations} mutations is not a count.");
        }
    }

    public function score(): float
    {
        return $this->mutations === 0 ? 0.0 : $this->caught / $this->mutations * 100;
    }
}
