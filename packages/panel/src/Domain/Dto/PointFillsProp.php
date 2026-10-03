<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * One point a panel page renders, by its id `<name>@<version>`, with its kind, its region when it
 * is a slot, how many contributions it shows, and its active contributions in the order the host
 * renders them (contributions.v1.json, `#/$defs/point`).
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
        public PointKind $kind,
        public ?Region $region,
        public Multiplicity $multiplicity,
        public ?int $max,
    ) {}
}
