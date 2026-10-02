<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * The mutations Pest made of one file that count towards its score and how many of them a test
 * caught: a test failed, or the run timed out, as Pest's score counts them. The mutations of the
 * file on the list of equivalent mutations (EquivalentMutations) are not applicable: they are left
 * out of both numbers, never counted as caught, and only their number is kept, to be shown.
 */
final readonly class MutationCount
{
    public function __construct(
        public int $mutations,
        public int $caught,
        public int $equivalent = 0,
    ) {
        if ($mutations < 0 || $caught < 0 || $caught > $mutations) {
            throw new InvalidArgumentException("{$caught} caught of {$mutations} mutations is not a count.");
        }

        if ($equivalent < 0) {
            throw new InvalidArgumentException("{$equivalent} equivalent mutations is not a count.");
        }
    }

    public function score(): float
    {
        return $this->mutations === 0 ? 0.0 : $this->caught / $this->mutations * 100;
    }
}
