<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Panel;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * A toolbar slot of the note list that renders at most three items, without props.
 */
#[Experimental]
#[PanelPoint(name: 'notes.list.toolbar', version: 1, kind: PointKind::Slot, page: 'notes.list', since: '1.1', label: 'fixture.points.notes_toolbar', region: Region::Toolbar, multiplicity: Multiplicity::Max, max: 3)]
final readonly class NotesToolbarV1 {}
