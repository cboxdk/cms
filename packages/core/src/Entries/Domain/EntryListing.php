<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryDetail;
use Cbox\Cms\Core\Entries\Domain\Dto\ListedEntry;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;

/**
 * What entry.get and entry.list read (PRD 5.4, 5.10, 8.8), in the read transaction under its actor
 * context: the entries whose home the context's regions reach or that its actor owns, the explicit
 * access predicate of PRD 5.10 with row level security as the backstop, and none without an actor.
 * Each read is a fixed number of statements, whatever the number of entries, heads or placements
 * (GUARDRAILS 4.1).
 */
#[Internal]
interface EntryListing
{
    /**
     * The entry with every variant head and every placement the context reaches, or null when the
     * entry is absent or its home is out of reach.
     */
    public function find(EntryId $entry): ?EntryDetail;

    /**
     * At most $limit entries the context reaches, newest first, after the query's entry when it
     * is not null, that match the query's filters, each with the title field of its type from the
     * shared head's draft as its fields, which the query pipeline strips to the reader's access;
     * the action reads one more than the query's limit, to know whether a next page follows.
     *
     * @return list<ListedEntry>
     */
    public function page(ListEntries $query, int $limit): array;
}
