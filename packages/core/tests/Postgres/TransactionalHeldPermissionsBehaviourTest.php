<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Tests\Access\HeldPermissionsBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * HeldPermissionsBehaviour against the container's HeldPermissions, TransactionalHeldPermissions on
 * the default connection, as the app role on real Postgres: the actors, the site and the roles and
 * grants of the behaviour's world are written by the testkit's fixture writers as the owner role,
 * and the delegate's credential is issued on behalf of the granted actor.
 */
final class TransactionalHeldPermissionsBehaviourTest extends TestCase
{
    use HeldPermissionsBehaviour;
    use RealPostgres;

    private ?ActorPrincipal $granted = null;

    private ?ActorPrincipal $ungranted = null;

    private ?ActorPrincipal $delegate = null;

    private ?ActorPrincipal $delegated = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $ids = new FakeIdGenerator(seed: 47, clock: $clock);
        $connections = app(ConnectionResolverInterface::class);
        $identity = new PostgresIdentitySeeder($connections, $clock, $ids);
        $access = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, $ids);
        $root = new PostgresStructureFixtures($connections, $clock, $ids)->site('held', [new Locale('da')])->root->id;

        $granted = $identity->addActor(ActorClass::Staff)->id;
        $ungranted = $identity->addActor(ActorClass::Staff)->id;
        $delegate = $identity->addActor(ActorClass::Service)->id;
        $identity->issue(new ServiceCredentialSpec($delegate, IssuerKind::Agent, ClassificationAccess::Confidential, $clock->now()->modify('+1 day'), [$granted]));

        $access->grant($granted, $access->role('reader', ClassificationAccess::Internal, self::names(self::READ, self::WRITE)), $root);
        $access->grant($granted, $access->role('auditor', ClassificationAccess::Internal, self::names(self::AUDIT)), $root, GrantEffect::Deny);
        $access->grant($delegate, $access->role('agent', ClassificationAccess::Sensitive, self::names(self::READ, self::AUDIT)), $root);

        $this->granted = $this->principal($granted);
        $this->ungranted = $this->principal($ungranted);
        $this->delegate = $this->principal($delegate);
        $this->delegated = $this->principal($delegate, $granted);
    }

    #[Override]
    protected function heldPermissions(): HeldPermissions
    {
        return app(HeldPermissions::class);
    }

    #[Override]
    protected function granted(): ActorPrincipal
    {
        return $this->granted ?? throw new LogicException('The world is not set up.');
    }

    #[Override]
    protected function ungranted(): ActorPrincipal
    {
        return $this->ungranted ?? throw new LogicException('The world is not set up.');
    }

    #[Override]
    protected function delegate(): ActorPrincipal
    {
        return $this->delegate ?? throw new LogicException('The world is not set up.');
    }

    #[Override]
    protected function delegated(): ActorPrincipal
    {
        return $this->delegated ?? throw new LogicException('The world is not set up.');
    }

    #[Test]
    public function it_refuses_to_read_inside_an_open_transaction_and_leaves_no_context_behind(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $this->heldPermissions()->of($this->granted(), self::names(self::READ));

        self::assertSame(0, $connection->transactionLevel());
        self::assertSame('', $connection->scalar("select current_setting('cbox_cms.actor', true)") ?? '');

        $connection->beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->heldPermissions()->of($this->granted(), self::names(self::READ));
        } finally {
            $connection->rollBack();
        }
    }

    private function principal(ActorId $actor, ActorId ...$onBehalfOf): ActorPrincipal
    {
        return new ActorPrincipal($actor, array_values($onBehalfOf), IssuerKind::Service, ClassificationAccess::Sensitive);
    }
}
