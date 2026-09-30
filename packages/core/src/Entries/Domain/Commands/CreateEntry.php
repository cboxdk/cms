<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Creates an entry of any type with its first revision (PRD 5.4, 6.4), version 1 of entry.create:
 * the entry's id, its type, its home node, which owns its content (PRD 5.10), and the fields of its
 * shared variant, the one variant of a type whose localization is none.
 *
 * The caller makes the entry's id, so a repeat of the call with the same idempotency key is the
 * same content, and the command expects the entry not to exist: a create of an id that exists is
 * version_conflict (invariant 11). The fields are validated against the type's schema version the
 * code was generated from (invariant 4), at the write stage, where an extension field is never
 * required (invariant 36).
 */
#[CommandName('entry.create', version: 1)]
#[Experimental]
final readonly class CreateEntry implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public NodeId $home,
        public FieldValues $fields,
    ) {}

    /**
     * The entry, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::absent($this->entry));
    }
}
