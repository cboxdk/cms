<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Handle;

/**
 * One choice of a `select` field: the stored value and its label.
 */
#[Internal]
final readonly class SelectOption
{
    public function __construct(
        public Handle $value,
        public string $label,
    ) {}
}
