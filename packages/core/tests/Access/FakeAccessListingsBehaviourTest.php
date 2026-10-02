<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Tests\TestCase;
use Closure;
use Override;

/**
 * FakeAccessListings against AccessListingsBehaviour, with each reader's context of ListingWorld.
 */
final class FakeAccessListingsBehaviourTest extends TestCase
{
    use AccessListingsBehaviour;

    #[Override]
    protected function listAs(string $reader, Closure $read): void
    {
        $read(ListingWorld::accessListings($reader));
    }
}
