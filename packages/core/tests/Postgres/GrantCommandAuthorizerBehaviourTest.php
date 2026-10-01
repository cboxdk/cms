<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Tests\Access\AuthorizationCases;
use Cbox\Cms\Core\Tests\Pipeline\CommandAuthorizerBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * CommandAuthorizerBehaviour against the container's CommandAuthorizer, GrantCommandAuthorizer on
 * the default connection, as the app role on real Postgres, over the access world with the roles
 * of AuthorizationCases, inside a transaction with the principal's context set as the command
 * transaction sets it.
 */
final class GrantCommandAuthorizerBehaviourTest extends TestCase
{
    use CommandAuthorizerBehaviour;
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
    protected function commandAuthorizer(): CommandAuthorizer
    {
        return app(CommandAuthorizer::class);
    }

    #[Override]
    protected function actor(): ActorPrincipal
    {
        return AccessWorld::alice();
    }

    #[Override]
    protected function permitted(): array
    {
        return AuthorizationCases::permitted();
    }

    #[Override]
    protected function refused(): array
    {
        return AuthorizationCases::refused();
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
