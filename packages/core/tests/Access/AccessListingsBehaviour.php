<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\AccessListings;
use Cbox\Cms\Core\Access\Domain\Dto\ListedGrant;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every AccessListings of the kernel does (PRD 5.10, 12.2), held against the fake the action
 * tests use and PostgresAccessListings, over the ListingWorld: every actor reads every role, in the
 * order of their ids, with its permissions sorted; an actor that holds grant.list reads the grants
 * that have not ended on the nodes its context reaches, in the order of their ids, with the
 * profile of its own actor and, at personal access, of every other; an actor that does not hold
 * grant.list reads none. Both page after an id.
 */
trait AccessListingsBehaviour
{
    /**
     * Gives AccessListings read under the context of the reader, an actor of ListingWorld::READERS.
     *
     * @param  Closure(AccessListings): void  $read
     */
    abstract protected function listAs(string $reader, Closure $read): void;

    #[Test]
    public function it_lists_every_role_in_the_order_of_its_ids_with_its_permissions_sorted(): void
    {
        $this->listAs(ListingWorld::EDITOR, static function (AccessListings $listings): void {
            [$admin, $desk] = ListingWorld::roles();

            Assert::assertEquals([$admin, $desk], $listings->roles(null, 10));
            Assert::assertEquals([$admin], $listings->roles(null, 1));
            Assert::assertEquals([$desk], $listings->roles(RoleId::fromString(ListingWorld::ADMIN_ROLE), 10));
            Assert::assertSame([], $listings->roles(RoleId::fromString(ListingWorld::DESK), 10));
        });
    }

    #[Test]
    public function it_lists_the_grants_on_every_node_the_context_reaches_with_their_profiles_at_personal_access(): void
    {
        $this->listAs(ListingWorld::ADMIN, static function (AccessListings $listings): void {
            [[$admin], [$editor], [$bob], [$denied]] = ListingWorld::grants();

            Assert::assertEquals([$admin, $editor, $bob, $denied], $listings->grants(null, 10));
            Assert::assertNull($bob->profile);
            Assert::assertEquals([$bob], $listings->grants(GrantId::fromString(ListingWorld::GRANT_EDITOR), 1));
        });
    }

    #[Test]
    public function it_lists_the_grants_only_on_the_nodes_the_context_reaches_with_only_the_readers_own_profile_below_personal(): void
    {
        $this->listAs(ListingWorld::EDITOR, static function (AccessListings $listings): void {
            [, [$editor]] = ListingWorld::grants();

            Assert::assertEquals([$editor], $listings->grants(null, 10));
        });

        $this->listAs(ListingWorld::ADMIN, static function (AccessListings $listings): void {
            Assert::assertSame(
                [ListingWorld::GRANT_ADMIN, ListingWorld::GRANT_EDITOR, ListingWorld::GRANT_BOB, ListingWorld::GRANT_DENIED],
                array_map(static fn (ListedGrant $grant): string => $grant->id->toString(), $listings->grants(null, 10)),
            );
        });
    }

    #[Test]
    public function it_lists_no_grant_to_an_actor_that_does_not_hold_grant_list(): void
    {
        $this->listAs(ListingWorld::SERVICE, static function (AccessListings $listings): void {
            Assert::assertSame([], $listings->grants(null, 10));
            Assert::assertCount(2, $listings->roles(null, 10));
        });
    }
}
