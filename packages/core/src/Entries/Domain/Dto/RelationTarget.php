<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * One target a relation field's value holds (PRD 5.11): the entry, and the attributes it carries
 * for a relation that declares them, with the text of its filterable attribute; or the entry or
 * node a link field names, with the locale of a link to an entry as its attribute.
 */
#[Internal]
final readonly class RelationTarget
{
    public function __construct(
        public EntryId|NodeId $target,
        public ?FieldMap $meta,
        public ?string $filterKey = null,
    ) {}
}
