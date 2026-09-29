<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/**
 * An extender's part of a type's composite version (PRD 11.2, 11.12 point 5): the namespace of its
 * extension fields and the version its extension files declare.
 */
#[Internal]
final readonly class ExtensionVersion
{
    public function __construct(
        public Owner $namespace,
        public int $version,
    ) {}
}
