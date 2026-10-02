<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * One mutation Pest made of a file and whether a test caught it: a test failed, or the run timed
 * out, as Pest's score counts it. The hash is Pest's id of the mutation: a hash of the file's real
 * path, the mutator and the mutated source, so two runs on the same checkout give the same
 * mutation the same hash. The line is where the mutated code starts and the mutator is Pest's
 * class that made it, which name the mutation the same way in every checkout, as the list of
 * equivalent mutations does (EquivalentMutations).
 */
final readonly class MutationOutcome
{
    public function __construct(
        public string $hash,
        public bool $caught,
        public int $line,
        public string $mutator,
    ) {
        if ($hash === '' || str_contains($hash, "\n")) {
            throw new InvalidArgumentException('A mutation needs a one-line hash.');
        }

        if ($line < 1) {
            throw new InvalidArgumentException("The mutation {$hash} starts on line {$line}, which no file has.");
        }

        if ($mutator === '' || str_contains($mutator, "\n")) {
            throw new InvalidArgumentException("The mutation {$hash} needs the one-line name of its mutator.");
        }
    }
}
