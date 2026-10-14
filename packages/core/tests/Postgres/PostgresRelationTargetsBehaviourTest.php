<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Entries\Adapter\PostgresRelationTargets;
use Cbox\Cms\Core\Pipeline\Domain\RelationTargets;
use Cbox\Cms\Core\Tests\Pipeline\RelationTargetsBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * RelationTargetsBehaviour against PostgresRelationTargets on real Postgres, as the app role inside
 * a transaction under the actor context of a staff member whose region is the root ROOT above
 * NODE; FAR is a root outside it, the home of FAR_NOTE. The entries are written as the superuser.
 */
final class PostgresRelationTargetsBehaviourTest extends TestCase
{
    use RealPostgres;
    use RelationTargetsBehaviour;

    private const string ROOT = '0192a0c0-0000-7000-8000-0000000005a1';

    private const string NODE = self::HOME_NODE;

    private const string FAR = self::FAR_NODE;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert(StorageTables::node(self::NODE, self::ROOT, StorageTables::label(self::ROOT)));
        $superuser->table('nodes')->insert(StorageTables::node(self::FAR, kind: 'site'));

        foreach ([[self::ARTICLE, self::ARTICLE_TYPE, self::NODE], [self::NOTE, self::NOTE_TYPE, self::NODE], [self::FAR_NOTE, self::NOTE_TYPE, self::FAR]] as [$entry, $type, $home]) {
            $superuser->table('entries')->insert([...StorageTables::entry($entry), 'type_id' => $type, 'home_node_id' => $home]);
        }

        $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff)->id;

        DB::connection()->beginTransaction();
        new ActorContext(app(ConnectionResolverInterface::class))->set(new AccessContext(
            new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [new AccessRegion(new NodePath(StorageTables::label(self::ROOT)))],
            ClassificationAccess::Internal,
        ));
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::connection()->rollBack();
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function relationTargets(): RelationTargets
    {
        return new PostgresRelationTargets(app(ConnectionResolverInterface::class));
    }
}
