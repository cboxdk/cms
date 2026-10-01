<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\QueryAuthorizerBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * QueryAuthorizerBehaviour against the container's QueryAuthorizer, PostgresQueryAuthorizer
 * on the default connection, as the app role on real Postgres over AccessWorld's tree: each test's
 * actor is a new staff member whose roles, permissions and grants the owner role writes, and the
 * authorization runs in a transaction with the context the AccessResolver sets for it.
 */
final class PostgresQueryAuthorizerBehaviourTest extends TestCase
{
    use QueryAuthorizerBehaviour;
    use RealPostgres;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        AccessWorld::seed();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function grantedActor(array $grants): ActorPrincipal
    {
        return AuthorizerWorld::grantedActor($grants);
    }

    #[Override]
    protected function queryAuthorizer(): QueryAuthorizer
    {
        return app(QueryAuthorizer::class);
    }

    #[Override]
    protected function within(Principal $principal, Closure $authorize): Authorization
    {
        return AuthorizerWorld::within($principal, $authorize);
    }
}
