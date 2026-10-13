<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * One target of a relation field in a variant's fields (PRD 5.11): the field's address without the
 * items' indexes, the target's ordinal among that field's targets in order from 0, and the target.
 */
#[Internal]
final readonly class RelationEdge
{
    public function __construct(
        public string $field,
        public int $ordinal,
        public EntryId $target,
    ) {}
}
