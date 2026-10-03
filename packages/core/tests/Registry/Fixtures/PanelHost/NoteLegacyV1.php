<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointDeprecation;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * The props of notes.legacy@1, a point of the panel build's host fixture.
 */
#[Stable]
#[PanelPoint(name: 'notes.legacy', version: 1, kind: PointKind::Slot, page: 'notes.detail', since: '1.0', label: 'fixture.points.note_legacy', region: Region::Aside, deprecated: new PointDeprecation(since: '1.0', removeIn: '2.0', replacement: 'notes.detail.sections@1'))]
final readonly class NoteLegacyV1 {}
