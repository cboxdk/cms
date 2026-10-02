<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Maintenance\Adapter\PostgresOperatorGenesis;
use Cbox\Cms\Core\Maintenance\Adapter\TransactionalAccessBootstrapState;
use Cbox\Cms\Core\Maintenance\Domain\AccessBootstrapState;
use Cbox\Cms\Core\Maintenance\Domain\Dto\Genesis;
use Cbox\Cms\Core\Tests\Maintenance\AccessBootstrapStateBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * AccessBootstrapStateBehaviour against TransactionalAccessBootstrapState on real Postgres, as the
 * app role, which reads neither other actors' grants nor nodes outside its regions itself: the
 * operator is installed by the genesis, and the actors, nodes, roles and grants are written by the
 * testkit's fixture writers as the owner role.
 */
final class TransactionalAccessBootstrapStateBehaviourTest extends TestCase
{
    use AccessBootstrapStateBehaviour;
    use RealPostgres;

    private const string AT = '2026-03-10T12:00:00Z';

    /** The genesis changeset, in the millisecond of AT, which an ended grant names. */
    private const string CHANGESET = '019cd79e-4600-7000-8000-0000000005c1';

    private ?FakeClock $clock = null;

    private ?FakeIdGenerator $ids = null;

    private ?StructureNode $root = null;

    private ?ActorId $operator = null;

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);
        parent::tearDown();
    }

    #[Override]
    protected function bootstrapState(): AccessBootstrapState
    {
        return new TransactionalAccessBootstrapState(app(ConnectionResolverInterface::class));
    }

    #[Override]
    protected function operatorContext(): AccessContext
    {
        if (! $this->operator instanceof ActorId) {
            $this->operator = new ActorId($this->ids()->next());
            app(PartitionFixtures::class)->coverClock($this->clock(), new DateInterval('P1D'));
            new PostgresOperatorGenesis(app(ConnectionResolverInterface::class), $this->clock(), config()->string('cbox-cms.database.owner_connection'))
                ->install(new Genesis($this->operator, ChangesetId::fromString(self::CHANGESET), $this->clock()->now(), new CorrelationId('install')));
        }

        return app(AccessContexts::class)->for(new ActorPrincipal($this->operator, [], IssuerKind::Service, IssuerKind::Service->maximumCeiling()));
    }

    #[Override]
    protected function otherContext(): AccessContext
    {
        $staff = $this->identity()->addActor(ActorClass::Staff)->id;

        return app(AccessContexts::class)->for(new ActorPrincipal($staff, [], IssuerKind::Human, IssuerKind::Human->maximumCeiling()));
    }

    #[Override]
    protected function addNode(): NodeId
    {
        $structure = new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $this->clock(), $this->ids());
        $this->root ??= $structure->site('bootstrapped', [new Locale('en')])->root;

        return $structure->node($this->root)->id;
    }

    #[Override]
    protected function grantTo(ActorClass $class, bool $ended = false): void
    {
        $actor = $this->identity()->addActor($class)->id;
        $node = $this->addNode();
        $access = $this->access();
        $grant = $access->grant($actor, $access->role('holder_'.substr(bin2hex(random_bytes(4)), 0, 8), ClassificationAccess::Public), $node);

        if ($ended) {
            StorageTables::superuser()->table('grants')->where('id', $grant->toString())->update(['ended_changeset_id' => self::CHANGESET]);
        }
    }

    #[Override]
    protected function addRole(RoleHandle $handle, ClassificationAccess $ceiling, array $permissions): RoleId
    {
        return $this->access()->role($handle->value, $ceiling, $permissions);
    }

    private function access(): PostgresAccessFixtures
    {
        return new PostgresAccessFixtures(app(ConnectionResolverInterface::class), $this->clock(), $this->ids());
    }

    private function identity(): PostgresIdentitySeeder
    {
        return new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $this->clock(), $this->ids());
    }

    private function clock(): FakeClock
    {
        return $this->clock ??= new FakeClock(new DateTimeImmutable(self::AT));
    }

    private function ids(): FakeIdGenerator
    {
        return $this->ids ??= new FakeIdGenerator(seed: 5301, clock: $this->clock());
    }
}
