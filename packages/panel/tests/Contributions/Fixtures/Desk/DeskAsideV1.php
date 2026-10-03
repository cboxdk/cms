<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * The props of desk.aside@1, a second slot of desk.overview, which the test pages render only
 * when a test says so, and which has no codec registered.
 */
#[Experimental]
#[PanelPoint(name: 'desk.aside', version: 1, kind: PointKind::Slot, page: 'desk.overview', since: '1.0', label: 'fixture.points.desk_aside', region: Region::Aside)]
final readonly class DeskAsideV1
{
    public function __construct(public string $note) {}
}
