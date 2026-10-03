<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;

/**
 * A contribution the server resolved as active for a viewer on a page (PRD 13.4): its compiled
 * fill, the point it contributes to, the point's props for that version, and the classification
 * access it is handed them and its data at, the lower of the viewer's and the addon's reads. The
 * point's codec writes the props at that access, so a member above it never reaches the addon.
 */
#[Experimental]
final readonly class ActiveFill
{
    public function __construct(
        public PanelFill $fill,
        public PointId $point,
        public object $props,
        public ClassificationAccess $access,
    ) {}

    /**
     * The query whose result the contribution gets as data, or null for none.
     */
    public function data(): ?CommandRef
    {
        return $this->fill->query;
    }
}
