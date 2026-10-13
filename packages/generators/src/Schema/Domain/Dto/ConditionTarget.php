<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The field a condition's reference names (gap G3): the field, its path within its set and the
 * top-level field it is, or is in, whose classification and variant it has.
 */
#[Internal]
final readonly class ConditionTarget
{
    public function __construct(
        public FieldBlueprint $field,
        public string $path,
        public FieldBlueprint $topLevel,
    ) {}
}
