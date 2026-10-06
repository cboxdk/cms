<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;
use Cbox\Cms\Core\Access\Domain\OwnHeldGrants;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every OwnHeldGrants of the kernel does (PRD 5.10, 13.4), held against the fake the action
 * tests use and PostgresOwnHeldGrants, over the ListingWorld: a reader gets the grants it holds
 * that have not ended, in the order of their ids, each with its role's ceiling, its node's path,
 * its effect, its locales and its role's permissions sorted; a reader without a grant gets none;
 * and no reader gets another actor's grants.
 */
trait OwnHeldGrantsBehaviour
{
    /**
     * Gives OwnHeldGrants read under the context of the reader, an actor of ListingWorld.
     *
     * @param  Closure(OwnHeldGrants): void  $read
     */
    abstract protected function heldAs(string $reader, Closure $read): void;

    #[Test]
    public function it_gives_the_reader_its_grant_with_its_role_s_ceiling_path_effect_and_permissions(): void
    {
        $this->heldAs(ListingWorld::ADMIN, static function (OwnHeldGrants $grants): void {
            $held = $grants->held();

            Assert::assertEquals(ListingWorld::heldGrants(ListingWorld::ADMIN), $held);
            Assert::assertCount(1, $held);
            Assert::assertSame(ListingWorld::ADMIN_ROLE, $held[0]->grant->role->toString());
            Assert::assertSame(ClassificationAccess::Personal, $held[0]->grant->roleCeiling);
            Assert::assertSame(ListingWorld::path(ListingWorld::ROOT), $held[0]->grant->node->value);
            Assert::assertSame(GrantEffect::Allow, $held[0]->grant->effect);
            Assert::assertNull($held[0]->grant->locales);
            Assert::assertSame(['actor.list', 'grant.list', 'role.list'], array_map(static fn (CommandName $name): string => $name->value, $held[0]->permissions));
            Assert::assertTrue($held[0]->permits(new CommandName('grant.list')));
            Assert::assertFalse($held[0]->permits(new CommandName('entry.revise')));
        });
    }

    #[Test]
    public function it_gives_every_grant_held_in_id_order_with_its_locales_and_effect(): void
    {
        $this->heldAs(ListingWorld::EDITOR, static function (OwnHeldGrants $grants): void {
            $held = $grants->held();

            Assert::assertEquals(ListingWorld::heldGrants(ListingWorld::EDITOR), $held);
            Assert::assertCount(2, $held);
            Assert::assertSame(['da', 'en'], array_map(static fn (object $locale): string => $locale->value, $held[0]->grant->locales ?? []));
            Assert::assertSame(GrantEffect::Allow, $held[0]->grant->effect);
            Assert::assertSame(ListingWorld::path(ListingWorld::NEWS), $held[0]->grant->node->value);
            Assert::assertSame(GrantEffect::Deny, $held[1]->grant->effect);
            Assert::assertSame(ListingWorld::path(ListingWorld::SPORT), $held[1]->grant->node->value);
            Assert::assertNull($held[1]->grant->locales);
            Assert::assertSame(['actor.list', 'entry.revise', 'grant.list'], array_map(static fn (CommandName $name): string => $name->value, $held[1]->permissions));
        });
    }

    #[Test]
    public function it_leaves_out_a_grant_that_has_ended(): void
    {
        $this->heldAs(ListingWorld::BOB, static function (OwnHeldGrants $grants): void {
            $held = $grants->held();

            Assert::assertEquals(ListingWorld::heldGrants(ListingWorld::BOB), $held);
            Assert::assertSame([ListingWorld::path(ListingWorld::CULTURE)], array_map(static fn (HeldGrant $grant): string => $grant->grant->node->value, $held));
        });
    }

    #[Test]
    public function it_gives_an_actor_without_grants_none(): void
    {
        $this->heldAs(ListingWorld::SERVICE, static function (OwnHeldGrants $grants): void {
            Assert::assertSame([], $grants->held());
        });
    }
}
