<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Tests\Access\AuthorizationCases;
use Cbox\Cms\Core\Tests\Reads\QueryAuthorizerBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * QueryAuthorizerBehaviour against the container's QueryAuthorizer, GrantQueryAuthorizer on the
 * default connection, as the app role on real Postgres, over the access world with the roles of
 * AuthorizationCases, inside a transaction with the principal's context set as the read's
 * transaction sets it.
 */
final class GrantQueryAuthorizerBehaviourTest extends TestCase
{
    use QueryAuthorizerBehaviour;
    use RealPostgres;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        AccessWorld::seed();
        AuthorizationGrants::add();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function queryAuthorizer(): QueryAuthorizer
    {
        return app(QueryAuthorizer::class);
    }

    #[Override]
    protected function actor(): ActorPrincipal
    {
        return AccessWorld::alice();
    }

    #[Override]
    protected function permittedQueries(): array
    {
        return [new CommandName(AuthorizationCases::SEE)];
    }

    #[Override]
    protected function refusedQueries(): array
    {
        return AuthorizationCases::refusedQueries();
    }

    #[Override]
    protected function begin(Principal $principal): AccessContext
    {
        app(DatabaseManager::class)->connection()->beginTransaction();

        return app(AccessResolver::class)->resolve($principal);
    }

    #[Override]
    protected function end(): void
    {
        app(DatabaseManager::class)->connection()->rollBack();
    }
}
