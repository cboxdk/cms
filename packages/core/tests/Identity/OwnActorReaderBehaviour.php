<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Core\Identity\Domain\Dto\OwnGrant;
use Cbox\Cms\Core\Identity\Domain\OwnActorReader;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every OwnActorReader of the kernel does (PRD 5.10, 5.16, 13.4), held against the fake the
 * action tests use and PostgresOwnActorReader, over the ListingWorld: a reader gets its own actor
 * with its class, state and version, its own profile whatever its classification access, and the
 * grants it holds that have not ended in the order of their ids, each with its role's handle,
 * node, effect and locales; an actor without a profile gets null for it; a reader without a grant
 * gets none; and no reader gets another actor's self.
 */
trait OwnActorReaderBehaviour
{
    /**
     * Gives OwnActorReader read under the context of the reader, an actor of ListingWorld::READERS.
     *
     * @param  Closure(OwnActorReader): void  $read
     */
    abstract protected function ownAs(string $reader, Closure $read): void;

    #[Test]
    public function it_gives_the_reader_its_own_actor_profile_and_grants(): void
    {
        $this->ownAs(ListingWorld::ADMIN, static function (OwnActorReader $reader): void {
            $own = $reader->own();

            Assert::assertNotNull($own);
            Assert::assertEquals(ListingWorld::own(ListingWorld::ADMIN), $own);
            Assert::assertSame(ActorClass::Staff, $own->class);
            Assert::assertSame('ada@example.com', $own->profile?->email->value);
            Assert::assertSame([ListingWorld::GRANT_ADMIN], array_map(static fn (OwnGrant $grant): string => $grant->id->toString(), $own->grants));
        });
    }

    #[Test]
    public function it_gives_the_profile_below_personal_access_and_every_grant_held_in_id_order_with_its_locales_and_effect(): void
    {
        $this->ownAs(ListingWorld::EDITOR, static function (OwnActorReader $reader): void {
            $own = $reader->own();

            Assert::assertNotNull($own);
            $grants = $own->grants;

            Assert::assertEquals(ListingWorld::own(ListingWorld::EDITOR), $own);
            Assert::assertSame('eve@example.com', $own->profile?->email->value);
            Assert::assertSame([ListingWorld::GRANT_EDITOR, ListingWorld::GRANT_DENIED], array_map(static fn (OwnGrant $grant): string => $grant->id->toString(), $grants));
            Assert::assertSame(['da', 'en'], array_map(static fn (object $locale): string => $locale->value, $grants[0]->locales ?? []));
            Assert::assertSame('desk', $grants[0]->roleHandle->value);
            Assert::assertSame(GrantEffect::Deny, $grants[1]->effect);
            Assert::assertNull($grants[1]->locales);
        });
    }

    #[Test]
    public function it_gives_null_for_a_missing_profile_and_leaves_out_a_grant_that_has_ended(): void
    {
        $this->ownAs(ListingWorld::BOB, static function (OwnActorReader $reader): void {
            $own = $reader->own();

            Assert::assertNotNull($own);
            Assert::assertEquals(ListingWorld::own(ListingWorld::BOB), $own);
            Assert::assertNull($own->profile);
            Assert::assertSame([ListingWorld::GRANT_BOB], array_map(static fn (OwnGrant $grant): string => $grant->id->toString(), $own->grants));
        });
    }

    #[Test]
    public function it_gives_a_service_actor_without_grants_its_own_self_with_no_grant(): void
    {
        $this->ownAs(ListingWorld::SERVICE, static function (OwnActorReader $reader): void {
            $own = $reader->own();

            Assert::assertNotNull($own);
            Assert::assertEquals(ListingWorld::own(ListingWorld::SERVICE), $own);
            Assert::assertSame(ActorClass::Service, $own->class);
            Assert::assertSame([], $own->grants);
        });
    }
}
