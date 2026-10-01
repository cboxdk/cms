<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Tests\Access\AccessWorldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Closure;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * QueryAuthorizerBehaviour against FakeQueryAuthorizer::granting(), with the grants in memory.
 */
final class FakeQueryAuthorizerBehaviourTest extends TestCase
{
    use QueryAuthorizerBehaviour;

    private ?FakePermissions $permissions = null;

    #[Override]
    protected function grantedActor(array $grants): ActorPrincipal
    {
        [$actor, $this->permissions] = AccessWorldPermissions::of($grants);

        return $actor;
    }

    #[Override]
    protected function queryAuthorizer(): QueryAuthorizer
    {
        return FakeQueryAuthorizer::granting($this->permissions ?? AccessWorldPermissions::of([])[1]);
    }

    #[Override]
    protected function within(Principal $principal, Closure $authorize): Authorization
    {
        return $authorize($principal instanceof ActorPrincipal ? new AccessContext($principal, [], ClassificationAccess::Public) : AccessContext::anonymous());
    }
}
