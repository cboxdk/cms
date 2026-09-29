<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Access\Adapter\PostgresAccessResolver;
use Cbox\Cms\Core\Access\Adapter\TransactionalAccessContexts;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Tests\Access\AccessContextsBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * AccessContextsBehaviour against the container's AccessContexts, TransactionalAccessContexts on
 * the default connection, as the app role on real Postgres, and what it adds: an actor's context is
 * the one the PostgresAccessResolver compiles from its grants, it leaves no transaction and no
 * actor context behind, and it refuses a connection that is already in a transaction.
 */
final class TransactionalAccessContextsBehaviourTest extends TestCase
{
    use AccessContextsBehaviour;
    use RealPostgres;

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function accessContexts(): AccessContexts
    {
        return app(AccessContexts::class);
    }

    #[Test]
    public function the_container_binds_the_transactional_adapter(): void
    {
        self::assertInstanceOf(TransactionalAccessContexts::class, app(AccessContexts::class));
    }

    #[Test]
    public function an_actor_gets_the_context_its_grants_compile_to(): void
    {
        AccessWorld::seed();
        $connection = DB::connection();

        $connection->beginTransaction();

        try {
            $expected = new PostgresAccessResolver(app(DatabaseManager::class), new AccessCompiler)->resolve(AccessWorld::alice());
        } finally {
            $connection->rollBack();
        }

        $context = $this->accessContexts()->for(AccessWorld::alice());

        self::assertEquals($expected, $context);
        self::assertNotSame([], $context->regions);
    }

    #[Test]
    public function it_leaves_no_transaction_and_no_actor_context_behind(): void
    {
        AccessWorld::seed();
        $connection = DB::connection();

        $this->accessContexts()->for(AccessWorld::alice());

        self::assertSame(0, $connection->transactionLevel());
        self::assertSame('', $connection->scalar("select coalesce(current_setting('".ActorContext::ACTOR."', true), '')"));
    }

    #[Test]
    public function it_refuses_a_connection_that_is_already_in_a_transaction(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('is already in a transaction');

            $this->accessContexts()->for(AccessWorld::alice());
        } finally {
            $connection->rollBack();
        }
    }
}
