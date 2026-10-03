<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions;

use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Panel\Boundary\Generated\Points\PanelPointCodecs;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PointCodec;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskCardsCodec;
use InvalidArgumentException;

/*
 * The codecs of the points' props (PRD 13.4): one per point id, found by it, and in the container
 * the panel's own, which composer generate:protocol lists, with those a module or addon tags.
 */

it('finds a point s codec by its id, and none for a point without one', function (): void {
    $codecs = ContributionWorld::pointCodecs();

    expect($codecs->find(new PointId(new PointName('desk.cards'), 1))?->codec)->toBeInstanceOf(DeskCardsCodec::class)
        ->and($codecs->find(new PointId(new PointName('desk.cards'), 2)))->toBeNull()
        ->and($codecs->find(new PointId(new PointName('desk.aside'), 1)))->toBeNull();
});

it('refuses two codecs for one point', function (): void {
    $codec = new PointCodec(new PointId(new PointName('desk.cards'), 1), new DeskCardsCodec);

    expect(fn (): PointCodecs => new PointCodecs($codec, $codec))->toThrow(InvalidArgumentException::class, 'desk.cards@1');
});

it('gives the container the panel s own codecs and the ones tagged', function (): void {
    $tagged = new PointCodec(new PointId(new PointName('desk.cards'), 1), new DeskCardsCodec);
    app()->instance('test.point-codec', $tagged);
    app()->tag('test.point-codec', PointCodecs::TAG);
    app()->forgetInstance(PointCodecs::class);

    $codecs = app(PointCodecs::class);

    expect($codecs->find($tagged->point))->toBe($tagged);

    foreach (PanelPointCodecs::all() as $own) {
        expect($codecs->find($own->point))->not->toBeNull();
    }
});
