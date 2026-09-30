<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementCreated;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementVisibilityChanged;
use Cbox\Cms\Core\Placements\Domain\Visibility;

// A regional desk takes a story onto its site: it places the entry below a section of the site
// with a slug in each language the site publishes in, and then opens the placement's window. The
// placement is hidden until its window opens; the kernel keeps one canonical placement of the
// entry per language.

it('places an entry below a node of a site, hidden, with a slug per locale', function (): void {
    $placement = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000901');

    $place = new CreatePlacement(
        $placement,
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000902'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000903'),
        SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000904'),
        [new LocaleSlug(new Locale('da'), new Slug('havnen-aabner')), new LocaleSlug(new Locale('en'), new Slug('harbour-opens'))],
    );

    expect($place->expectedVersions()->reads)->toEqual([ReadVersion::absent($placement)])
        ->and(PlacementCreated::type()->name)->toBe('placement.created');
});

it('opens the window of the placement in one locale at the version the caller read', function (): void {
    $placement = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000901');
    $from = new DateTimeImmutable('2026-10-01T06:00:00Z');
    $window = new TimeWindow($from, $from->modify('+7 days'));

    $open = new SetPlacementWindow($placement, new AggregateVersion(1), new Locale('da'), $window);
    $hide = new SetPlacementWindow($placement, new AggregateVersion(2), new Locale('da'), null);

    expect($open->expectedVersions()->of($placement))->toEqual(ReadVersion::at($placement, new AggregateVersion(1)))
        ->and(new PlacementWindowSet($placement, new Locale('da'), $window)->makesPublic())->toBeTrue()
        ->and(new PlacementWindowSet($placement, new Locale('da'), $hide->window)->makesPublic())->toBeFalse()
        ->and(Visibility::of($window, $from->modify('-1 hour')))->toBe(Visibility::Scheduled)
        ->and(Visibility::nextTransition($window, $from->modify('-1 hour')))->toEqual($from)
        ->and(PlacementVisibilityChanged::type()->name)->toBe('placement.visibility_changed');
});
