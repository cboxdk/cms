<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * One mutation Pest made of a file and whether a test caught it: a test failed, or the run timed
 * out, as Pest's score counts it. The hash is Pest's id of the mutation: a hash of the file's real
 * path, the mutator and the mutated source, so two runs on the same checkout give the same
 * mutation the same hash.
 */
final readonly class MutationOutcome
{
    public function __construct(
        public string $hash,
        public bool $caught,
    ) {
        if ($hash === '' || str_contains($hash, "\n")) {
            throw new InvalidArgumentException('A mutation needs a one-line hash.');
        }
    }
}
