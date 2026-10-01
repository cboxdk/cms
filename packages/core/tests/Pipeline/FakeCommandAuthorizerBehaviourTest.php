<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Tests\Access\AccessWorldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Closure;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * CommandAuthorizerBehaviour against FakeCommandAuthorizer::granting(), with the grants in memory.
 */
final class FakeCommandAuthorizerBehaviourTest extends TestCase
{
    use CommandAuthorizerBehaviour;

    private ?FakePermissions $permissions = null;

    #[Override]
    protected function grantedActor(array $grants): ActorPrincipal
    {
        [$actor, $this->permissions] = AccessWorldPermissions::of($grants);

        return $actor;
    }

    #[Override]
    protected function commandAuthorizer(): CommandAuthorizer
    {
        return FakeCommandAuthorizer::granting($this->permissions ?? AccessWorldPermissions::of([])[1]);
    }

    #[Override]
    protected function delegateOf(ActorPrincipal $person, array $grants): ActorPrincipal
    {
        return AccessWorldPermissions::delegateOf($this->permissions ?? throw new LogicException('Give the person its grants first.'), $person, $grants);
    }

    #[Override]
    protected function within(Principal $principal, Closure $authorize): Authorization
    {
        return $authorize($principal instanceof ActorPrincipal ? new AccessContext($principal, [], ClassificationAccess::Public) : AccessContext::anonymous());
    }
}
