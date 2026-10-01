<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * The Postgres side of the authorizers' behaviour traits, over AccessWorld's tree, which seed()
 * writes first: an actor whose roles, permissions and grants the owner role writes, and a
 * transaction with the context the container's AccessResolver sets for a principal.
 */
final class AuthorizerWorld
{
    public const string ACTOR = '0192a0c0-0000-7000-8000-0000000000c9';

    /**
     * @param  list<array{string, list<string>, string, GrantEffect, list<string>|null}>  $grants  role handle, permissions, node, effect, locales
     */
    public static function grantedActor(array $grants): ActorPrincipal
    {
        StorageTables::superuser()->table('actors')->insert(['id' => self::ACTOR, 'actor_class' => 'staff', 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => AccessWorld::CREATED_AT]);
        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 77, clock: $clock));
        $actor = ActorId::fromString(self::ACTOR);
        $roles = [];

        foreach ($grants as [$handle, $names, $node, $effect, $locales]) {
            $roles[$handle] ??= $fixtures->role('authorizer_'.$handle, ClassificationAccess::Internal, array_map(static fn (string $name): CommandName => new CommandName($name), $names));
            $fixtures->grant($actor, $roles[$handle], NodeId::fromString($node), $effect, $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales));
        }

        return new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Internal);
    }

    /**
     * @param  Closure(AccessContext): Authorization  $authorize
     */
    public static function within(Principal $principal, Closure $authorize): Authorization
    {
        $connection = app(DatabaseManager::class)->connection();
        $connection->beginTransaction();

        try {
            return $authorize(app(AccessResolver::class)->resolve($principal));
        } finally {
            $connection->rollBack();
        }
    }
}
