<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Points\Fixtures;

use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;

/**
 * The props of the actions of the note list's toolbar, a stable point, so its schema is held by
 * the compatibility lock.
 */
#[Stable]
#[PanelPoint(name: 'notes.list.toolbar', version: 1, kind: PointKind::Action, page: 'notes.list', since: '1.0', label: 'fixture.points.notes_toolbar')]
final readonly class NoteToolbarV1
{
    public function __construct(
        public int $count,
        public ?string $filter,
    ) {}
}
