<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * One aggregate a dry run would change, as the summary of the report names it (PRD 6.2 phase 6):
 * the aggregate's key, `<kind>:<id>` as AggregateRef::aggregateKey() writes it, the version it was
 * read at, or null when the write creates it, the version the commit would give it, and how many
 * of the plan's mutations change it. It is the AggregateChange of the report without the typed
 * reference, which the JSON form of a dry run cannot carry.
 */
#[Experimental]
final readonly class VersionChange
{
    public function __construct(
        public string $aggregate,
        public ?AggregateVersion $before,
        public AggregateVersion $after,
        public int $mutations,
    ) {
        if ($aggregate === '' || ! str_contains($aggregate, ':')) {
            throw InvalidWriteResult::aggregateKey($aggregate);
        }

        if ($mutations < 1) {
            throw InvalidWriteResult::changeWithoutMutations($aggregate);
        }

        if ($before instanceof AggregateVersion ? ! $after->equals($before->next()) : ! $after->equals(AggregateVersion::first())) {
            throw InvalidWriteResult::versionChange($aggregate);
        }
    }

    public static function of(AggregateChange $change): self
    {
        return new self($change->aggregate->aggregateKey(), $change->before, $change->after, $change->mutations);
    }

    /**
     * Whether the write creates the aggregate.
     */
    public function creates(): bool
    {
        return ! $this->before instanceof AggregateVersion;
    }
}
