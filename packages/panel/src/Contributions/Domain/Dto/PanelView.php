<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\PanelPoints\PageName;

/**
 * One request for a panel page behind the login (PRD 13.4): the page, the viewer who signed in,
 * the points the page renders with their props, in the order the page lists them, and what the
 * page is about.
 */
#[Experimental]
final readonly class PanelView
{
    /**
     * @param  list<RenderedPoint>  $points
     */
    public function __construct(
        public PageName $page,
        public ActorPrincipal $viewer,
        public array $points,
        public ViewSubject $subject = new ViewSubject,
    ) {}
}
