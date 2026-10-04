<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Shell\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Panel\Shell\Domain\Shell;

/**
 * The props of shell.page@1, the pages of the panel's shell (PRD 13.4, section 3.4 of the panel
 * extension architecture): a page point without props. A PageContribution to it is a page of the
 * addon at `<prefix>/x/<namespace>/<path>`, whose only props are the result of its data query,
 * run as the viewer, so the same data can be read over REST; the page is shown to a viewer who
 * holds the permission its scope requires.
 */
#[Experimental]
#[PanelPoint(name: Shell::PAGES, version: 1, kind: PointKind::Page, page: Shell::PAGE, since: '1.0', label: 'panel.points.shell_page')]
final readonly class ShellPageV1 {}
