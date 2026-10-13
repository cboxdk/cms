<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementSlugSet;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementSlug;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementSlugChanged;

// An editor fixes a typo in a story's URL after it went live: the placement gets another slug in
// Danish at the version the editor read. The old URL goes to the slug history and answers 301 to
// the new one; the placement stays live, because a rename is not a visibility change.

it('renames a placement in one locale at the version the caller read', function (): void {
    $placement = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000911');

    $rename = new SetPlacementSlug($placement, new AggregateVersion(2), new Locale('da'), new Slug('havnen-aabner'));
    $mutation = new PlacementSlugSet($placement, new Locale('da'), new Slug('havnen-aabner'));

    expect($rename->expectedVersions()->of($placement))->toEqual(ReadVersion::at($placement, new AggregateVersion(2)))
        ->and($mutation->aggregate())->toBe($placement)
        ->and(PlacementSlugChanged::type()->name)->toBe('placement.slug_changed');
});
