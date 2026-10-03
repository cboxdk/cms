<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use InvalidArgumentException;

/**
 * Which panel points cms:panel:points lists: every point, the one point with an id
 * (`<name>@<version>`), or, by a name without a version, every version of the point with that
 * name and every point the page with that name renders. A point's name has the form of a page's
 * name, so one value selects both.
 */
#[Experimental]
final readonly class PanelPointsRequest
{
    /**
     * @throws InvalidArgumentException when both are given
     */
    public function __construct(
        public ?PointId $point = null,
        public ?PageName $name = null,
    ) {
        if ($point instanceof PointId && $name instanceof PageName) {
            throw new InvalidArgumentException('Select panel points by an id or by a name, not by both.');
        }
    }
}
