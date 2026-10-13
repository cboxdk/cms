<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A reference of one conditional field to the field its condition reads (gap G3): the path of the
 * field read within its set, such as `dimensions.size`, and the comparison that reads it, which a
 * problem names.
 */
#[Internal]
final readonly class ConditionEdge
{
    public function __construct(
        public string $target,
        public FieldCondition $comparison,
    ) {}
}
