<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Override;

/**
 * FakeOwnActorReader against OwnActorReaderBehaviour, with each reader's context of ListingWorld.
 */
final class FakeOwnActorReaderBehaviourTest extends TestCase
{
    use OwnActorReaderBehaviour;

    #[Override]
    protected function ownAs(string $reader, Closure $read): void
    {
        $read(ListingWorld::ownActorReader($reader));
    }
}
