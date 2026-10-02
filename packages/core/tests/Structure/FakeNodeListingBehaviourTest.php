<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Override;

/**
 * FakeNodeListing against NodeListingBehaviour, with each reader's context of ListingWorld.
 */
final class FakeNodeListingBehaviourTest extends TestCase
{
    use NodeListingBehaviour;

    #[Override]
    protected function listAs(string $reader, Closure $read): void
    {
        $read(ListingWorld::nodeListing($reader));
    }
}
