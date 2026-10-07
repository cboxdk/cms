<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\PointDowncastRefused;
use Cbox\Cms\Core\Registry\Domain\PointDowncasts;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Registry\Domain\UnknownPanelPoint;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Panel\NoteSectionsV1;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Panel\NoteSubmitV1;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Panel\NoteSubmitV2;

/*
 * The props of an older version of a panel point are built from the props of its newest version
 * through the older class's declared downcast (PRD 13.4); the newest version's props are handed
 * out as they are. The registry is the Panel fixture's, whose notes.form.submit@1 downcasts from
 * notes.form.submit@2.
 */

function downcasts(): PointDowncasts
{
    return new PointDowncasts(PanelRegistryFixture::compiled());
}

it('builds the props of an older version from the newest version\'s through its downcast', function (): void {
    $props = downcasts()->props(PointId::fromString('notes.form.submit@1'), new NoteSubmitV2('note.create', 3));

    expect($props)->toBeInstanceOf(NoteSubmitV1::class)
        ->and($props)->toEqual(new NoteSubmitV1('note.create'));
});

it('hands out the newest version\'s props as they are', function (): void {
    $newest = new NoteSubmitV2('note.create', 3);

    expect(downcasts()->props(PointId::fromString('notes.form.submit@2'), $newest))->toBe($newest);
});

it('gives no props for any version of a point whose props the page holds, which the server has none of', function (): void {
    expect(downcasts()->props(PointId::fromString('notes.form.submit@2'), null))->toBeNull()
        ->and(downcasts()->props(PointId::fromString('notes.form.submit@1'), null))->toBeNull();
});

it('refuses props that are not the newest version\'s, also those of the target itself', function (): void {
    expect(static fn (): ?object => downcasts()->props(PointId::fromString('notes.form.submit@1'), new NoteSubmitV1('note.create')))
        ->toThrow(PointDowncastRefused::class, sprintf('The props of notes.form.submit@1 are built from the props of the newest version of its point, notes.form.submit@2, which are %s. They were given %s.', NoteSubmitV2::class, NoteSubmitV1::class));
});

it('refuses a point the registry does not hold', function (): void {
    expect(static fn (): ?object => downcasts()->props(PointId::fromString('notes.form.submit@3'), new NoteSubmitV2('note.create', 3)))
        ->toThrow(UnknownPanelPoint::class, 'No panel point is registered as notes.form.submit@3.');
});

it('refuses an older version without a downcast, which cms:build refuses too', function (): void {
    $sections = static fn (int $version, string $class): PanelPointEntry => new PanelPointEntry(
        new PanelPoint('notes.detail.sections', $version, PointKind::Slot, 'notes.detail', '1.0', 'fixture.points.note_sections', Region::Sections),
        $class,
        'acme/cms-notes',
        PointStability::Experimental,
    );
    $registry = new CompiledRegistry([], [], panel: [$sections(1, NoteSectionsV1::class), $sections(2, NewerSections::class)]);

    expect(static fn (): ?object => new PointDowncasts($registry)->props(PointId::fromString('notes.detail.sections@1'), new NewerSections))
        ->toThrow(PointDowncastRefused::class, 'The panel point notes.detail.sections@1 is an older version and its props class does not implement');
});
