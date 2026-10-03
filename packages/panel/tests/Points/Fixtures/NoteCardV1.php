<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Points\Fixtures;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\PanelPoints\DowncastsFromNewest;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * The props of the note card slot, version 1, which contributions written for it keep receiving:
 * built from the props of version 2 through its downcast.
 *
 * @implements DowncastsFromNewest<NoteCardV2>
 */
#[Experimental]
#[PanelPoint(name: 'notes.detail.card', version: 1, kind: PointKind::Slot, page: 'notes.detail', since: '1.0', label: 'fixture.points.note_card', region: Region::Sections)]
final readonly class NoteCardV1 implements DowncastsFromNewest
{
    public function __construct(
        public ActorId $owner,
        public string $title,
    ) {}

    public static function downcast(object $newest): static
    {
        return new self($newest->owner, $newest->title);
    }
}
