<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * Which panel point cms:panel:fills lists the contributions of.
 */
#[Experimental]
final readonly class PanelFillsRequest
{
    public function __construct(public PointId $point) {}
}
