<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;

/**
 * The MutationWriter of each mutation class (PRD 6.2 phase 7): the writers the core's service
 * provider finds under the container tag TAG, one per class. A command task adds a writer by
 * tagging it; a mutation without one cannot be committed.
 */
#[Internal]
final readonly class MutationWriters
{
    /** The container tag the writers are registered under. */
    public const string TAG = 'cbox-cms.mutation-writers';

    /** @var array<string, MutationWriter> by lowercase mutation class */
    private array $writers;

    /**
     * @throws UncommittableChangeset when two writers write the same class
     */
    public function __construct(MutationWriter ...$writers)
    {
        $byClass = [];

        foreach ($writers as $writer) {
            $class = strtolower(ltrim($writer->writes(), '\\'));

            if (isset($byClass[$class])) {
                throw UncommittableChangeset::duplicateWriter($writer->writes());
            }

            $byClass[$class] = $writer;
        }

        $this->writers = $byClass;
    }

    /**
     * @throws UncommittableChangeset when no writer writes the mutation's class
     */
    public function for(Mutation $mutation): MutationWriter
    {
        return $this->writers[strtolower($mutation::class)] ?? throw UncommittableChangeset::noWriter($mutation::class);
    }
}
