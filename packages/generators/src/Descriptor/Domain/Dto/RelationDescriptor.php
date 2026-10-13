<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Handle;

/**
 * What a relation field's descriptor holds beside its form (PRD 5.11): whether each target carries
 * attributes, so the field takes the form of a group whose nested fields are `entry` and the
 * attributes, and the owner's sortable field a filterable relation's released edges are sorted by.
 */
#[Internal]
final readonly class RelationDescriptor
{
    public function __construct(
        public bool $meta,
        public ?Handle $sortBy,
    ) {}
}
