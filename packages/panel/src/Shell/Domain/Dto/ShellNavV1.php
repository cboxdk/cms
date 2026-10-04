<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Shell\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Panel\Shell\Domain\Shell;

/**
 * The props of shell.nav@1, the navigation of the panel's shell (PRD 13.4, section 3.4 of the
 * panel extension architecture): a nav point without props. A NavContribution to it is an entry
 * of the navigation that opens one of the addon's pages, shown to a viewer who holds the
 * permission its scope requires and may open the page it links to, in render order; the entries
 * are in the command palette too.
 */
#[Experimental]
#[PanelPoint(name: Shell::NAV, version: 1, kind: PointKind::Nav, page: Shell::PAGE, since: '1.0', label: 'panel.points.shell_nav')]
final readonly class ShellNavV1 {}
