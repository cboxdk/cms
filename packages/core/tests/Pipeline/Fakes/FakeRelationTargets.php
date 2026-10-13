<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Pipeline\Domain\Dto\EntryTypes;
use Cbox\Cms\Core\Pipeline\Domain\RelationTargets;
use Override;

/**
 * The entries a test says exist, with their types: an entry it put nothing on reads as absent, as
 * an entry the actor cannot read does. It counts its reads, one per call with entries, as the
 * implementation makes one statement per call. RelationTargetsBehaviour holds it to
 * PostgresRelationTargets.
 */
final class FakeRelationTargets implements RelationTargets
{
    /** The calls that read entries. */
    public int $reads = 0;

    /** @var array<string, TypeId> by entry */
    private array $entries = [];

    public function with(EntryId $entry, TypeId $type): self
    {
        $this->entries[$entry->toString()] = $type;

        return $this;
    }

    #[Override]
    public function types(array $entries): EntryTypes
    {
        if ($entries === []) {
            return new EntryTypes;
        }

        $this->reads++;
        $types = new EntryTypes;

        foreach ($entries as $entry) {
            $type = $this->entries[$entry->toString()] ?? null;

            if ($type instanceof TypeId) {
                $types = $types->with($entry, $type);
            }
        }

        return $types;
    }
}
