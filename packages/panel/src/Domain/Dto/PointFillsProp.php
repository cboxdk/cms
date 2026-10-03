<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * One point a panel page renders, by its id `<name>@<version>`, with its active contributions in
 * the order the host renders them (contributions.v1.json, `#/$defs/point`).
 */
#[Internal]
final readonly class PointFillsProp
{
    /**
     * @param  list<FillProp>  $fills
     */
    public function __construct(
        public string $point,
        public array $fills,
    ) {}
}
