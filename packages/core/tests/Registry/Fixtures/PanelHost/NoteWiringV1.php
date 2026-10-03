<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * The props of notes.wiring@1, a point of the panel build's host fixture.
 */
#[Internal]
#[PanelPoint(name: 'notes.wiring', version: 1, kind: PointKind::Slot, page: 'notes.detail', since: '1.0', label: 'fixture.points.note_wiring', region: Region::Aside)]
final readonly class NoteWiringV1 {}
