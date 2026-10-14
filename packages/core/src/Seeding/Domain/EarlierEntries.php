<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededIdentity;
use Override;
use Random\Randomizer;

/**
 * The targets of the relations of seeded entry n (PRD 5.11): an entry of the data set with a lower
 * index, which a chunk before it or the same chunk creates first, so the target exists when the
 * relation is checked and written. It draws up to TRIES indexes below n and takes the first whose
 * type the relation allows; when none is, it takes entry n itself if its type is allowed, and
 * otherwise has no target. An entry's id and type follow from its index alone
 * (EntryGenerator::identity()), so the same seed names the same targets. A link to an entry takes
 * any earlier entry, or entry n itself when it is the first.
 */
#[Internal]
final readonly class EarlierEntries implements SeedRelations
{
    /** How many earlier entries a relation tries before it gives up. */
    public const int TRIES = 16;

    public function __construct(
        private EntryGenerator $entries,
        private SeededIdentity $own,
        private int $index,
    ) {}

    #[Override]
    public function pick(array $types, Randomizer $random): ?EntryId
    {
        for ($try = 0; $this->index > 0 && $try < self::TRIES; $try++) {
            $earlier = $this->entries->identity($random->getInt(0, $this->index - 1));

            if ($this->allowed($earlier, $types)) {
                return $earlier->id;
            }
        }

        return $this->allowed($this->own, $types) ? $this->own->id : null;
    }

    /**
     * An earlier entry of any type, or entry n itself when it is the first.
     */
    #[Override]
    public function any(Randomizer $random): EntryId
    {
        return $this->index > 0 ? $this->entries->identity($random->getInt(0, $this->index - 1))->id : $this->own->id;
    }

    /**
     * @param  list<TypeName>  $types
     */
    private function allowed(SeededIdentity $entry, array $types): bool
    {
        return array_any($types, static fn (TypeName $type): bool => $type->equals($entry->type->definition->name));
    }
}
