<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use InvalidArgumentException;

/**
 * Seeds a chunk of entries of any types in one changeset (GUARDRAILS 4.3), version 1 of
 * seed.entries: each entry's id, type, home node and first revision's fields, and whether the
 * revision is released in the same changeset. Only the seeder issues it, as the internal issuer
 * seed on the bulk stream, with an idempotency key derived from its chunk, so a chunk that runs
 * again replays instead of seeding twice. It is exposed on no surface.
 *
 * An entry of the chunk that exists already is left as it is, so a run that seeds more entries
 * than an earlier one with the same seed only adds the rest.
 */
#[CommandName('seed.entries', version: 1)]
#[Experimental]
final readonly class SeedEntries implements Command
{
    /** @var non-empty-list<SeededEntry> */
    public array $entries;

    /**
     * @throws InvalidArgumentException for no entries or an entry given twice
     */
    public function __construct(SeededEntry ...$entries)
    {
        if ($entries === []) {
            throw new InvalidArgumentException('A seed chunk has at least one entry.');
        }

        $seen = [];

        foreach ($entries as $entry) {
            $id = $entry->entry->toString();

            if (isset($seen[$id])) {
                throw new InvalidArgumentException(sprintf('The entry %s is given twice in one seed chunk.', $id));
            }

            $seen[$id] = true;
        }

        $this->entries = array_values($entries);
    }
}
