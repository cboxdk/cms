<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementState;
use Cbox\Cms\Core\Placements\Domain\Visibility;

/*
 * The placements of an entry in a locale: each found by its id, and none for an id it has not.
 */

it('finds the state of each of its placements by id, and none for another', function (): void {
    $first = new PlacementState(PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a01'), new AggregateVersion(1), Visibility::Live, null, true);
    $second = new PlacementState(PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a02'), new AggregateVersion(2), Visibility::Hidden, null, false);
    $placements = new LocalePlacements(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a00'), new Locale('da'), [$first, $second]);

    expect($placements->of($first->placement))->toBe($first)
        ->and($placements->of($second->placement))->toBe($second)
        ->and($placements->of(PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a03')))->toBeNull()
        ->and($placements->canonical())->toBe($first);
});
