<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Identity\Domain\ActorListing;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedActor;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every ActorListing of the kernel does (PRD 5.16, 12.2), held against the fake the action
 * tests use and PostgresActorListing, over the ListingWorld: an actor that holds actor.list reads
 * the staff actors, not the service actor, in the order of their ids, with the profile of its own
 * actor and, at personal access, of every other; an actor that does not hold actor.list reads none.
 * It pages after an id.
 */
trait ActorListingBehaviour
{
    /**
     * Gives ActorListing read under the context of the reader, an actor of ListingWorld::READERS.
     *
     * @param  Closure(ActorListing): void  $read
     */
    abstract protected function listAs(string $reader, Closure $read): void;

    #[Test]
    public function it_lists_the_staff_actors_with_their_profiles_at_personal_access(): void
    {
        $this->listAs(ListingWorld::ADMIN, static function (ActorListing $listing): void {
            [[$admin], [$editor], [$bob]] = ListingWorld::actors();

            Assert::assertEquals([$admin, $editor, $bob], $listing->staff(null, 10));
            Assert::assertNull($bob->profile);
            Assert::assertEquals([$editor], $listing->staff(ActorId::fromString(ListingWorld::ADMIN), 1));
        });
    }

    #[Test]
    public function it_gives_only_the_readers_own_profile_below_personal_access(): void
    {
        $this->listAs(ListingWorld::EDITOR, static function (ActorListing $listing): void {
            [[$admin], [$editor], [$bob]] = ListingWorld::actors();
            $withoutProfile = static fn (ListedActor $actor): ListedActor => new ListedActor($actor->id, $actor->state, $actor->version, null);

            Assert::assertEquals([$withoutProfile($admin), $editor, $bob], $listing->staff(null, 10));
        });
    }

    #[Test]
    public function it_lists_no_actor_to_an_actor_that_does_not_hold_actor_list(): void
    {
        $this->listAs(ListingWorld::SERVICE, static function (ActorListing $listing): void {
            Assert::assertSame([], $listing->staff(null, 10));
        });
    }
}
