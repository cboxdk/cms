<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Actions\MapPanelFills;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFillsRequest;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownPanelPoint;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\PanelRegistryFixture;

/*
 * MapPanelFills, behind cms:panel:fills (PRD 13.2, 13.4): the contributions to a panel point in the
 * order the host renders them, priority with the lowest first, then the addon's namespace, then the
 * contribution's id. A point the registry does not hold is refused, and so is a registry cache that
 * cannot be read.
 */

it('maps the contributions to a point in render order', function (): void {
    $point = new MapPanelFills(PanelRegistryFixture::cache())->map(new PanelFillsRequest(PointId::fromString('notes.detail.sections@1')));

    expect($point->id()->toString())->toBe('notes.detail.sections@1')
        ->and(array_map(static fn (PanelFill $fill): string => $fill->contribution->value.' '.$fill->priority, $point->fills))
        ->toBe(['cms.summary 100', 'reviews.stars 200']);
});

it('maps a point without contributions as none', function (): void {
    expect(new MapPanelFills(PanelRegistryFixture::cache())->map(new PanelFillsRequest(PointId::fromString('notes.form.submit@2')))->fills)->toBe([]);
});

it('refuses a point the registry does not hold, and names the registered points', function (): void {
    expect(fn (): mixed => new MapPanelFills(PanelRegistryFixture::cache())->map(new PanelFillsRequest(PointId::fromString('notes.detail.sections@2'))))
        ->toThrow(UnknownPanelPoint::class, 'No panel point is registered as notes.detail.sections@2. The registered panel points are notes.detail.sections@1,');
});

it('refuses a registry cache that is missing or damaged', function (): void {
    $damaged = PanelRegistryFixture::cache();
    $damaged->damage();
    $request = new PanelFillsRequest(PointId::fromString('notes.detail.sections@1'));

    expect(fn (): mixed => new MapPanelFills(new FakeRegistryCache)->map($request))->toThrow(RegistryCacheMissing::class)
        ->and(fn (): mixed => new MapPanelFills($damaged)->map($request))->toThrow(MalformedRegistryCache::class);
});
