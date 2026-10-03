<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\FieldDefinition;

/**
 * A value a writer gave for a field it may not set (PRD 2.31, 12.2): where in the command it is,
 * and the definition of the field that closes it, the top-level field or the nested field of a
 * group.
 */
#[Internal]
final readonly class ClosedValue
{
    public function __construct(
        public FieldPath $path,
        public FieldDefinition $field,
    ) {}
}
