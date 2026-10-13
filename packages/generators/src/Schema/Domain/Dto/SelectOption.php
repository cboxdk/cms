<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Handle;

/**
 * One choice of a `select` field: the stored value, its label and the label by locale when the
 * file gives a map (gap G2).
 */
#[Internal]
final readonly class SelectOption
{
    /**
     * @param  array<string, string>  $labels  the label by locale, in file order
     */
    public function __construct(
        public Handle $value,
        public string $label,
        public array $labels = [],
    ) {}
}
