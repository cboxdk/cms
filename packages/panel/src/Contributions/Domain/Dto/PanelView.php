<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PanelLocale;

/**
 * One request for a panel page behind the login (PRD 13.4): the page, the viewer who signed in,
 * the points the page renders with their props, in the order the page lists them, what the page is
 * about, and the locale the page is shown in, the application's, whose texts the addons' active
 * contributions get (Catalogues).
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
        public PanelLocale $locale = PanelLocale::FALLBACK,
    ) {}
}
