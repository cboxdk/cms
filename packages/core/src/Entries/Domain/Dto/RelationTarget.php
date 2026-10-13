<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * One target a relation field's value holds (PRD 5.11): the entry, and the attributes it carries
 * for a relation that declares them, with the text of its filterable attribute.
 */
#[Internal]
final readonly class RelationTarget
{
    public function __construct(
        public EntryId $entry,
        public ?FieldMap $meta,
        public ?string $filterKey = null,
    ) {}
}
