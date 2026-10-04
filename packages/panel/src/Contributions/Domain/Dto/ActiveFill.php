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
 * fill, the point it contributes to, the point's props for that version, the classification
 * access it is handed them and its data at, the lower of the viewer's and the addon's reads, and
 * the query whose result it gets as data on this page, or null: a slot fill's query wherever it is
 * active, and a page's only on the page itself, so the page's query runs once, when the page is
 * shown, and never on every page that lists it in the shell. The point's codec writes the props at
 * that access, so a member above it never reaches the addon.
 */
#[Experimental]
final readonly class ActiveFill
{
    public function __construct(
        public PanelFill $fill,
        public PointId $point,
        public object $props,
        public ClassificationAccess $access,
        private ?CommandRef $data = null,
    ) {}

    /**
     * The query whose result the contribution gets as data on this page, or null for none.
     */
    public function data(): ?CommandRef
    {
        return $this->data;
    }
}
