<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Placements\Domain\Dto\SlugClaim;
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;

/*
 * A slug is read as an aggregate: at version 1 when another placement holds it, absent when free.
 */

it('reads a taken slug at version 1 and a free one as absent', function (): void {
    $slug = new PlacementSlugRef(NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007a1'), new Locale('da'), new Slug('harbour'));

    expect(new SlugClaim($slug, true)->read()->version)->toEqual(new AggregateVersion(1))
        ->and(new SlugClaim($slug, true)->read()->aggregate)->toBe($slug)
        ->and(new SlugClaim($slug, false)->read()->existed())->toBeFalse()
        ->and(new SlugClaim($slug, false)->read()->aggregate)->toBe($slug);
});
