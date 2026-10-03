<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;

/**
 * The props of notes.page@1, a point of the panel build's host fixture.
 */
#[Stable]
#[PanelPoint(name: 'notes.page', version: 1, kind: PointKind::Page, page: 'shell', since: '1.0', label: 'fixture.points.notes_page')]
final readonly class NotesPageV1 {}
