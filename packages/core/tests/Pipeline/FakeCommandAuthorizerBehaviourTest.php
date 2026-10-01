<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Tests\Access\AuthorizationCases;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * CommandAuthorizerBehaviour against FakeCommandAuthorizer::byGrants(), permitted what ALICE's
 * roles allow in AuthorizationCases, node by node.
 */
final class FakeCommandAuthorizerBehaviourTest extends TestCase
{
    use CommandAuthorizerBehaviour;

    private ?FakeCommandAuthorizer $authorizer = null;

    #[Override]
    protected function commandAuthorizer(): CommandAuthorizer
    {
        if ($this->authorizer instanceof FakeCommandAuthorizer) {
            return $this->authorizer;
        }

        $alice = ActorId::fromString(AccessWorld::ALICE);
        $revise = new CommandName(AuthorizationCases::REVISE);

        return $this->authorizer = FakeCommandAuthorizer::byGrants()
            ->permit($alice, $revise, NodeId::fromString(AccessWorld::NEWS))
            ->permit($alice, $revise, NodeId::fromString(AccessWorld::FOOTBALL))
            ->permit($alice, $revise, NodeId::fromString(AccessWorld::CULTURE), [new Locale('da')])
            ->permit($alice, new CommandName(AuthorizationCases::CREATE), NodeId::fromString(AccessWorld::NEWS));
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
        return $principal instanceof ActorPrincipal ? new AccessContext($principal, [], ClassificationAccess::Public) : AccessContext::anonymous();
    }

    #[Override]
    protected function end(): void {}
}
