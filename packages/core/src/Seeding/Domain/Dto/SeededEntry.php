<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * One entry of a seeded data set: its id, type and home node, the fields of its first revision, and
 * whether that revision is released in the same changeset.
 */
#[Experimental]
final readonly class SeededEntry
{
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public NodeId $home,
        public FieldValues $fields,
        public bool $release,
    ) {}
}
