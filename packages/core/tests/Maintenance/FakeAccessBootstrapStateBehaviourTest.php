<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Maintenance\Domain\AccessBootstrapState;
use Cbox\Cms\Core\Maintenance\Domain\Dto\ExistingRole;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeAccessBootstrapState;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * AccessBootstrapStateBehaviour against the fake the bootstrap's action tests use.
 */
final class FakeAccessBootstrapStateBehaviourTest extends TestCase
{
    use AccessBootstrapStateBehaviour;

    private ?FakeAccessBootstrapState $state = null;

    private ?FakeIdGenerator $ids = null;

    #[Override]
    protected function bootstrapState(): AccessBootstrapState
    {
        return $this->fake();
    }

    #[Override]
    protected function operatorContext(): AccessContext
    {
        $operator = new ActorId($this->ids()->next());
        $this->fake()->operator = $operator;

        return $this->context($operator, IssuerKind::Service);
    }

    #[Override]
    protected function otherContext(): AccessContext
    {
        return $this->context(new ActorId($this->ids()->next()), IssuerKind::Human);
    }

    #[Override]
    protected function addNode(): NodeId
    {
        $node = new NodeId($this->ids()->next());
        $this->fake()->addNode($node);

        return $node;
    }

    #[Override]
    protected function grantTo(ActorClass $class, bool $ended = false): void
    {
        if ($class === ActorClass::Staff) {
            $this->fake()->staffGranted = true;
        }
    }

    #[Override]
    protected function addRole(RoleHandle $handle, ClassificationAccess $ceiling, array $permissions): RoleId
    {
        $role = new RoleId($this->ids()->next());
        $this->fake()->roles[$handle->value] = new ExistingRole($role, $ceiling, $permissions);

        return $role;
    }

    private function fake(): FakeAccessBootstrapState
    {
        return $this->state ??= new FakeAccessBootstrapState;
    }

    private function ids(): FakeIdGenerator
    {
        return $this->ids ??= new FakeIdGenerator;
    }

    private function context(ActorId $actor, IssuerKind $issuer): AccessContext
    {
        return new AccessContext(new ActorPrincipal($actor, [], $issuer, $issuer->maximumCeiling()), [], ClassificationAccess::Public);
    }
}
