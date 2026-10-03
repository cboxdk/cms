<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Panel;

use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\PanelPoints\DowncastsFromNewest;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Tighten;

/**
 * The first version of the decorator of the note form's submit button, built from the props of the
 * second through its downcast.
 *
 * @implements DowncastsFromNewest<NoteSubmitV2>
 */
#[Stable]
#[PanelPoint(name: 'notes.form.submit', version: 1, kind: PointKind::Decorator, page: 'notes.form', since: '1.0', label: 'fixture.points.note_submit', tightens: [Tighten::DisabledReason, Tighten::Description])]
final readonly class NoteSubmitV1 implements DowncastsFromNewest
{
    public function __construct(public string $command) {}

    public static function downcast(object $newest): static
    {
        return new self($newest->command);
    }
}
