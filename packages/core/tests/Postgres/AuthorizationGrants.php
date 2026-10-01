<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Tests\Access\AuthorizationCases;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * The roles AuthorizationCases adds to the access world for ALICE, written by the testkit's
 * fixtures as the owner role: translator (entry.revise) on CULTURE in the locale da, reader
 * (probe.see) on SPORT and auditor (probe.audit) denied on NEWS.
 */
final class AuthorizationGrants
{
    public static function add(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 5105, clock: $clock));
        $alice = ActorId::fromString(AccessWorld::ALICE);

        $fixtures->grant($alice, $fixtures->role('translator', ClassificationAccess::Internal, [new CommandName(AuthorizationCases::REVISE)]), NodeId::fromString(AccessWorld::CULTURE), locales: [new Locale('da')]);
        $fixtures->grant($alice, $fixtures->role('reader', ClassificationAccess::Internal, [new CommandName(AuthorizationCases::SEE)]), NodeId::fromString(AccessWorld::SPORT));
        $fixtures->grant($alice, $fixtures->role('auditor', ClassificationAccess::Internal, [new CommandName('probe.audit')]), NodeId::fromString(AccessWorld::NEWS), GrantEffect::Deny);
    }
}
