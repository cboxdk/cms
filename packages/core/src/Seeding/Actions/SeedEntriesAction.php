<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedEntriesAggregates;
use Cbox\Cms\Core\Seeding\Domain\SeedReader;
use Override;

/**
 * seed.entries (GUARDRAILS 4.3): one composed plan for the chunk, with the mutations of each entry
 * that does not exist yet as entry.create and variant.release plan them (PRD 5.4, 5.6, 6.4): the
 * entry, its first revision, the head moved to it, and, for an entry the seeder releases, the
 * release of that revision in the same changeset, which the kernel validates at the release stage
 * as the plan holds it. The plan has four sub-plans, one per step, each with that step of every
 * entry, so an entry's steps keep their order and the commit writes each step of the whole chunk
 * at once (BatchMutationWriter). An entry of the chunk that exists is left out. It reads the chunk
 * in two statements through the SeedReader, whatever its size.
 *
 * It is exposed on no surface: only the seeder calls it, as the internal issuer seed.
 *
 * @implements WriteAction<SeedEntries, SeedEntriesAggregates>
 */
#[Action(handles: SeedEntries::class)]
#[Internal]
final readonly class SeedEntriesAction implements WriteAction
{
    public function __construct(private SeedReader $reader) {}

    /**
     * @param  SeedEntries  $command
     */
    #[Override]
    public function resolve(Command $command): SeedEntriesAggregates
    {
        $existing = [];

        foreach ($this->reader->existing(array_map(static fn (SeededEntry $entry): EntryId => $entry->entry, $command->entries)) as $id) {
            $existing[$id->toString()] = true;
        }

        $absent = [];
        $homes = [];

        foreach ($command->entries as $entry) {
            if (! isset($existing[$entry->entry->toString()])) {
                $absent[] = $entry->entry;
                $homes[$entry->home->toString()] = $entry->home;
            }
        }

        return new SeedEntriesAggregates($absent, $homes === [] ? [] : $this->reader->nodes(array_values($homes)));
    }

    /**
     * @param  SeedEntries  $command
     * @param  SeedEntriesAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $shared = VariantKey::shared();
        $first = RevisionNumber::first();
        $created = [];
        $revisions = [];
        $heads = [];
        $releases = [];

        foreach ($command->entries as $entry) {
            if (! $aggregates->isAbsent($entry->entry)) {
                continue;
            }

            $created[] = new EntryCreated($entry->entry, $entry->type, $entry->home);
            $revisions[] = new RevisionCreated($entry->entry, $entry->type, $shared, $first, $entry->fields);
            $heads[] = new HeadMoved($entry->entry, $shared, null, $first);

            if ($entry->release) {
                $releases[] = new VariantReleased($entry->entry, $entry->type, $shared, $first);
            }
        }

        return $created === [] ? Plan::empty() : new Plan(new Plan(...$created), new Plan(...$revisions), new Plan(...$heads), new Plan(...$releases));
    }
}
