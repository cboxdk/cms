<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

/**
 * The mutations of one source as the list of equivalent mutations leaves them (EquivalentMutations):
 * the count that the score is taken over, without the listed mutations, and each entry of the list
 * for the source that no longer names a surviving mutation, which fails the step.
 */
final readonly class EquivalentJudgement
{
    /**
     * @param  list<string>  $stale  each stale entry, with what is wrong with it
     */
    public function __construct(
        public MutationCount $count,
        public array $stale,
    ) {}
}
