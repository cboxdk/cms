<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Override;

/**
 * FakeActorListing against ActorListingBehaviour, with each reader's context of ListingWorld.
 */
final class FakeActorListingBehaviourTest extends TestCase
{
    use ActorListingBehaviour;

    #[Override]
    protected function listAs(string $reader, Closure $read): void
    {
        $read(ListingWorld::actorListing($reader));
    }
}
