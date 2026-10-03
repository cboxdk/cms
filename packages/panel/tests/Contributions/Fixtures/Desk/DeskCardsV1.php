<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * The props of desk.cards@1, the slot of the test-only page desk.overview: a public note and a
 * confidential memo, which DeskCardsCodec leaves out below confidential access.
 */
#[Experimental]
#[PanelPoint(name: 'desk.cards', version: 1, kind: PointKind::Slot, page: 'desk.overview', since: '1.0', label: 'fixture.points.desk_cards', region: Region::Sections)]
final readonly class DeskCardsV1
{
    public function __construct(
        public string $note,
        public string $memo,
    ) {}
}
