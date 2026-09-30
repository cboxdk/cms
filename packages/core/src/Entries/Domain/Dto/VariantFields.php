<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * The fields of one variant of an entry, as the type table's row of a stage holds them.
 */
#[Internal]
final readonly class VariantFields
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
        public FieldValues $fields,
    ) {}
}
