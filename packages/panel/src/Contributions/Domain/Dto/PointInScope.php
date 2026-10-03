<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;

/**
 * A point a page renders with its props for that version and the fills to it that are enabled
 * and in scope, before the viewer's permissions are applied (ResolveContributions).
 */
#[Experimental]
final readonly class PointInScope
{
    /**
     * @param  non-empty-list<PanelFill>  $fills
     */
    public function __construct(
        public PanelPointEntry $point,
        public object $props,
        public array $fills,
    ) {}
}
