<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use DateTimeImmutable;

/**
 * An entry as entry.list gives it (PRD 5.4, 13.4): its system columns, which every type shares,
 * and its fields, the title field its type's editor descriptor names with the value of the shared
 * head's draft, so a list shows an entry by its title. The release state is the shared variant's,
 * or null for an entry without a shared head; the last change is the newest of the entry's
 * creation, its revisions, its head snapshots and its releases and withdrawals, in UTC. The fields
 * hold at most the title field, and none when the type names no title field, the draft holds no
 * value in it or was written under another schema version than the type's; the query pipeline
 * strips them to the reader's classification access, as it strips every read's content.
 */
#[Experimental]
final readonly class ListedEntry
{
    public function __construct(
        public EntryId $id,
        public TypeId $type,
        public NodeId $home,
        public EntryLifecycle $lifecycle,
        public ?ReleaseState $state,
        public DateTimeImmutable $changedAt,
        public AggregateVersion $version,
        public FieldValues $fields = new FieldValues,
    ) {}

    /**
     * The same entry with other fields.
     */
    public function withFields(FieldValues $fields): self
    {
        return new self($this->id, $this->type, $this->home, $this->lifecycle, $this->state, $this->changedAt, $this->version, $fields);
    }
}
