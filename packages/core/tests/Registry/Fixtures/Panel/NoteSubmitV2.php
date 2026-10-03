<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Panel;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Tighten;

/**
 * The second version of the decorator of the note form's submit button, internal.
 */
#[Internal]
#[PanelPoint(name: 'notes.form.submit', version: 2, kind: PointKind::Decorator, page: 'notes.form', since: '1.2', label: 'fixture.points.note_submit', tightens: [Tighten::ToneTowardsDanger])]
final readonly class NoteSubmitV2
{
    public function __construct(public string $command, public int $version) {}
}
