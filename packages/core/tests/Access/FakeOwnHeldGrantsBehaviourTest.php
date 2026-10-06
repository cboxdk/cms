<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Core\Tests\Access\Fakes\FakeOwnHeldGrants;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Override;

/**
 * FakeOwnHeldGrants against OwnHeldGrantsBehaviour, with each reader's context of ListingWorld: the
 * fake follows the context the FakeAccessResolver set, as the adapter reads the actor of the
 * transaction's context.
 */
final class FakeOwnHeldGrantsBehaviourTest extends TestCase
{
    use OwnHeldGrantsBehaviour;

    #[Override]
    protected function heldAs(string $reader, Closure $read): void
    {
        $access = ListingWorld::accessResolver();
        $access->begin();

        try {
            $access->resolve(ListingWorld::principal($reader));
            $read(FakeOwnHeldGrants::following(ListingWorld::permissions(), $access));
        } finally {
            $access->rollBack();
        }
    }
}
