<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Cbox\Cms\Core\Access\Domain\GrantSlotRef;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every GrantReader of the kernel does (PRD 5.10), held against the fake the action tests use
 * and PostgresGrantReader: as ALICE of AccessWorld, whose regions reach NEWS less SPORT but
 * FOOTBALL, and CULTURE, it reads a grant of another actor on a node she reaches, ended or not, and
 * no grant on a node she does not reach; it reads every role with its permissions, sorted; and it
 * says whether a slot holds a grant that has not ended.
 */
trait GrantReaderBehaviour
{
    public const string GRANT_ROLE = '0192a0c0-0000-7000-8000-000000000e51';

    public const string GRANT_NEWS = '0192a0c0-0000-7000-8000-000000000e52';

    public const string GRANT_SPORT = '0192a0c0-0000-7000-8000-000000000e53';

    public const string GRANT_ENDED = '0192a0c0-0000-7000-8000-000000000e54';

    /**
     * Writes the role, a role of BOB's on NEWS in da and en at version 2, one on SPORT, and one on
     * CULTURE that has ended, and gives a reader as ALICE. A role's permissions may come in any
     * order.
     *
     * @param  list<StoredRole>  $roles
     * @param  list<StoredGrant>  $grants
     * @param  Closure(GrantReader): void  $read
     */
    abstract protected function readAsAlice(array $roles, array $grants, Closure $read): void;

    #[Test]
    public function it_reads_a_grant_of_another_actor_on_a_node_the_context_reaches(): void
    {
        $this->readAsAlice(...$this->world(static function (GrantReader $reader): void {
            $grant = $reader->grant(GrantId::fromString(self::GRANT_NEWS));

            Assert::assertEquals(new StoredGrant(
                GrantId::fromString(self::GRANT_NEWS),
                ActorId::fromString(AccessWorld::BOB),
                RoleId::fromString(self::GRANT_ROLE),
                NodeId::fromString(AccessWorld::NEWS),
                GrantEffect::Allow,
                [new Locale('da'), new Locale('en')],
                new AggregateVersion(2),
                false,
            ), $grant);
            Assert::assertTrue($reader->grant(GrantId::fromString(self::GRANT_ENDED))?->ended);
        }));
    }

    #[Test]
    public function it_reads_no_grant_on_a_node_the_context_does_not_reach_and_none_that_does_not_exist(): void
    {
        $this->readAsAlice(...$this->world(static function (GrantReader $reader): void {
            Assert::assertNull($reader->grant(GrantId::fromString(self::GRANT_SPORT)));
            Assert::assertNull($reader->grant(GrantId::fromString('0192a0c0-0000-7000-8000-000000000e5f')));
        }));
    }

    #[Test]
    public function it_reads_a_role_with_its_permissions_sorted_and_no_role_that_does_not_exist(): void
    {
        $this->readAsAlice(...$this->world(static function (GrantReader $reader): void {
            Assert::assertEquals(
                new StoredRole(RoleId::fromString(self::GRANT_ROLE), ClassificationAccess::Confidential, [new CommandName('entry.create'), new CommandName('entry.revise')], AggregateVersion::first()),
                $reader->role(RoleId::fromString(self::GRANT_ROLE)),
            );
            Assert::assertNull($reader->role(RoleId::fromString('0192a0c0-0000-7000-8000-000000000e5e')));
        }));
    }

    #[Test]
    public function it_says_whether_the_actor_holds_the_role_on_the_node_with_a_grant_that_has_not_ended(): void
    {
        $this->readAsAlice(...$this->world(static function (GrantReader $reader): void {
            $bob = ActorId::fromString(AccessWorld::BOB);
            $role = RoleId::fromString(self::GRANT_ROLE);

            Assert::assertTrue($reader->held(new GrantSlotRef($bob, $role, NodeId::fromString(AccessWorld::NEWS))));
            Assert::assertTrue($reader->held(new GrantSlotRef($bob, $role, NodeId::fromString(AccessWorld::SPORT))));
            Assert::assertFalse($reader->held(new GrantSlotRef($bob, $role, NodeId::fromString(AccessWorld::CULTURE))));
            Assert::assertFalse($reader->held(new GrantSlotRef(ActorId::fromString(AccessWorld::ALICE), $role, NodeId::fromString(AccessWorld::NEWS))));
        }));
    }

    /**
     * A grant of BOB's of GRANT_ROLE.
     *
     * @param  list<Locale>|null  $locales
     */
    private static function storedGrant(string $id, string $node, GrantEffect $effect, ?array $locales, int $version, bool $ended): StoredGrant
    {
        return new StoredGrant(
            GrantId::fromString($id),
            ActorId::fromString(AccessWorld::BOB),
            RoleId::fromString(self::GRANT_ROLE),
            NodeId::fromString($node),
            $effect,
            $locales,
            new AggregateVersion($version),
            $ended,
        );
    }

    /**
     * @param  Closure(GrantReader): void  $read
     * @return array{list<StoredRole>, list<StoredGrant>, Closure(GrantReader): void}
     */
    private function world(Closure $read): array
    {
        return [
            [new StoredRole(RoleId::fromString(self::GRANT_ROLE), ClassificationAccess::Confidential, [new CommandName('entry.revise'), new CommandName('entry.create')], AggregateVersion::first())],
            [
                self::storedGrant(self::GRANT_NEWS, AccessWorld::NEWS, GrantEffect::Allow, [new Locale('da'), new Locale('en')], 2, false),
                self::storedGrant(self::GRANT_SPORT, AccessWorld::SPORT, GrantEffect::Deny, null, 1, false),
                self::storedGrant(self::GRANT_ENDED, AccessWorld::CULTURE, GrantEffect::Allow, null, 2, true),
            ],
            $read,
        ];
    }
}
