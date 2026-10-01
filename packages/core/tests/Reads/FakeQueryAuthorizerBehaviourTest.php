<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Tests\Access\AuthorizationCases;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * QueryAuthorizerBehaviour against FakeQueryAuthorizer::byGrants(), permitted the read ALICE's
 * reader role names in AuthorizationCases.
 */
final class FakeQueryAuthorizerBehaviourTest extends TestCase
{
    use QueryAuthorizerBehaviour;

    #[Override]
    protected function queryAuthorizer(): QueryAuthorizer
    {
        return FakeQueryAuthorizer::byGrants()->permit(ActorId::fromString(AccessWorld::ALICE), new CommandName(AuthorizationCases::SEE));
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
        return $principal instanceof ActorPrincipal ? new AccessContext($principal, [], ClassificationAccess::Public) : AccessContext::anonymous();
    }

    #[Override]
    protected function end(): void {}
}
