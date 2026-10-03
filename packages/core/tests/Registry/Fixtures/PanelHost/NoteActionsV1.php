<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;

/**
 * The props of notes.detail.actions@1, a point of the panel build's host fixture.
 */
#[Stable]
#[PanelPoint(name: 'notes.detail.actions', version: 1, kind: PointKind::Action, page: 'notes.detail', since: '1.0', label: 'fixture.points.note_actions')]
final readonly class NoteActionsV1
{
    public function __construct(public string $note, public int $count) {}
}
