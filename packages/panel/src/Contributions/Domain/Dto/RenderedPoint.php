<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PointName;

/**
 * A point a panel page renders (PRD 13.4), by name, with its props as the newest version of the
 * point declares them: an object of that version's props class. The contributions to an older
 * version of the point get the props its declared downcast builds from these.
 */
#[Experimental]
final readonly class RenderedPoint
{
    public function __construct(
        public PointName $name,
        public object $props,
    ) {}
}
