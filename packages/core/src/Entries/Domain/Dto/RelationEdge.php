<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * One target of a relation field in a variant's fields (PRD 5.11): the field's address without the
 * items' indexes, the target's ordinal among that field's targets in order from 0, the target, the
 * attributes it carries for a relation that declares them with the text of its filterable one, the
 * filter key, and for a released edge of a filterable relation the value of its sort_by field, its
 * sort key.
 */
#[Internal]
final readonly class RelationEdge
{
    public function __construct(
        public string $field,
        public int $ordinal,
        public EntryId $target,
        public ?FieldMap $meta = null,
        public ?string $filterKey = null,
        public ?FieldValue $sortKey = null,
    ) {}
}
