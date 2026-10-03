<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Actions\DescribePanelPoints;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointsRequest;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownPanelPoint;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\PanelRegistryFixture;
use InvalidArgumentException;

/*
 * DescribePanelPoints, behind cms:panel:points (PRD 13.2, 13.4): the panel points of the registry by
 * name and version, all of them or those an id, a point's name or a page selects. A selection that
 * matches nothing is refused with the registered points, and so is a registry cache that cannot be
 * read.
 */

/**
 * @return list<string>
 */
function describedPoints(PanelPointsRequest $request): array
{
    return array_map(
        static fn (PanelPointEntry $point): string => $point->id()->toString(),
        new DescribePanelPoints(PanelRegistryFixture::cache())->describe($request),
    );
}

it('describes every point of the registry by name and version', function (): void {
    expect(describedPoints(new PanelPointsRequest))->toBe(['notes.detail.sections@1', 'notes.form.field@1', 'notes.form.submit@1', 'notes.form.submit@2', 'notes.list.toolbar@1'])
        ->and(new DescribePanelPoints(PanelRegistryFixture::cache())->describe(new PanelPointsRequest))->toEqual(PanelRegistryFixture::compiled()->panel);
});

it('selects one point by its id, the versions of a point by its name, and the points of a page', function (PanelPointsRequest $request, array $ids): void {
    expect(describedPoints($request))->toBe($ids);
})->with([
    'an id' => [new PanelPointsRequest(point: PointId::fromString('notes.form.submit@1')), ['notes.form.submit@1']],
    'a point\'s name' => [new PanelPointsRequest(name: new PageName('notes.form.submit')), ['notes.form.submit@1', 'notes.form.submit@2']],
    'a page' => [new PanelPointsRequest(name: new PageName('notes.form')), ['notes.form.field@1', 'notes.form.submit@1', 'notes.form.submit@2']],
]);

it('refuses an id, a name or a page that selects no point, and names the registered points', function (PanelPointsRequest $request, string $message): void {
    expect(fn (): mixed => new DescribePanelPoints(PanelRegistryFixture::cache())->describe($request))->toThrow(UnknownPanelPoint::class, $message);
})->with([
    'an id' => [new PanelPointsRequest(point: PointId::fromString('notes.form.submit@3')), 'No panel point is registered as notes.form.submit@3. The registered panel points are notes.detail.sections@1, notes.form.field@1, notes.form.submit@1, notes.form.submit@2, notes.list.toolbar@1.'],
    'a page' => [new PanelPointsRequest(name: new PageName('shell')), 'No registered panel point is named shell or rendered by a page of that name.'],
]);

it('describes no point of a registry without points, and refuses every selection there', function (): void {
    $cache = new FakeRegistryCache;
    $cache->write(CompiledRegistry::empty());

    expect(new DescribePanelPoints($cache)->describe(new PanelPointsRequest))->toBe([])
        ->and(fn (): mixed => new DescribePanelPoints($cache)->describe(new PanelPointsRequest(name: new PageName('shell'))))
        ->toThrow(UnknownPanelPoint::class, 'The registry holds no panel points.');
});

it('refuses a request that selects by an id and a name at once', function (): void {
    expect(fn (): PanelPointsRequest => new PanelPointsRequest(PointId::fromString('shell.nav@1'), new PageName('shell')))
        ->toThrow(InvalidArgumentException::class, 'Select panel points by an id or by a name, not by both.');
});

it('refuses a registry cache that is missing or damaged', function (): void {
    $damaged = PanelRegistryFixture::cache();
    $damaged->damage();

    expect(fn (): mixed => new DescribePanelPoints(new FakeRegistryCache)->describe(new PanelPointsRequest))->toThrow(RegistryCacheMissing::class)
        ->and(fn (): mixed => new DescribePanelPoints($damaged)->describe(new PanelPointsRequest))->toThrow(MalformedRegistryCache::class);
});
