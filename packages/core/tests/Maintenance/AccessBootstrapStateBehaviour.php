<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Maintenance\Domain\AccessBootstrapState;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * What every AccessBootstrapState does, the fake and the Postgres one alike: it reads only as the
 * installation operator; a staff member's grant, also an ended one, ends the bootstrap and a
 * service actor's does not; it says whether the node exists; and it gives the role with the handle
 * with its ceiling and its permissions sorted, or none.
 */
trait AccessBootstrapStateBehaviour
{
    public const string ABSENT_NODE = '01936f5e-8a2b-7c3d-9e4f-0000000005c9';

    abstract protected function bootstrapState(): AccessBootstrapState;

    /**
     * The access context of the installation operator, installed first.
     */
    abstract protected function operatorContext(): AccessContext;

    /**
     * The access context of an active staff actor that is not the operator.
     */
    abstract protected function otherContext(): AccessContext;

    abstract protected function addNode(): NodeId;

    /**
     * Grants a role to a new active actor of the class on a node, and ends the grant when $ended.
     */
    abstract protected function grantTo(ActorClass $class, bool $ended = false): void;

    /**
     * @param  list<CommandName>  $permissions
     */
    abstract protected function addRole(RoleHandle $handle, ClassificationAccess $ceiling, array $permissions): RoleId;

    #[Test]
    public function it_reads_nothing_under_another_context_than_the_operators(): void
    {
        $this->operatorContext();
        $node = $this->addNode();
        $refused = false;

        try {
            $this->bootstrapState()->read($this->otherContext(), $node, new RoleHandle('administrator'));
        } catch (Throwable) {
            $refused = true;
        }

        Assert::assertTrue($refused, 'Only the installation operator reads the bootstrap state.');
    }

    #[Test]
    public function it_reads_a_fresh_installation_as_open(): void
    {
        $operator = $this->operatorContext();
        $node = $this->addNode();

        $state = $this->bootstrapState()->read($operator, $node, new RoleHandle('administrator'));
        $absent = $this->bootstrapState()->read($operator, NodeId::fromString(self::ABSENT_NODE), new RoleHandle('administrator'));

        Assert::assertFalse($state->staffGranted);
        Assert::assertTrue($state->nodeExists);
        Assert::assertNull($state->role);
        Assert::assertFalse($absent->nodeExists);
    }

    #[Test]
    public function it_counts_a_staff_members_grant_and_not_a_service_actors(): void
    {
        $operator = $this->operatorContext();
        $node = $this->addNode();
        $this->grantTo(ActorClass::Service);

        Assert::assertFalse($this->bootstrapState()->read($operator, $node, new RoleHandle('administrator'))->staffGranted, 'A service actor\'s grant leaves the bootstrap open.');

        $this->grantTo(ActorClass::Staff, ended: true);

        Assert::assertTrue($this->bootstrapState()->read($operator, $node, new RoleHandle('administrator'))->staffGranted, 'A staff member\'s grant ends the bootstrap, also once it has ended.');
    }

    #[Test]
    public function it_gives_the_role_with_the_handle_with_its_permissions_sorted(): void
    {
        $operator = $this->operatorContext();
        $node = $this->addNode();
        $role = $this->addRole(new RoleHandle('administrator'), ClassificationAccess::Internal, [new CommandName('grant.assign'), new CommandName('entry.create')]);
        $this->addRole(new RoleHandle('editor'), ClassificationAccess::Public, []);

        $found = $this->bootstrapState()->read($operator, $node, new RoleHandle('administrator'))->role;
        $empty = $this->bootstrapState()->read($operator, $node, new RoleHandle('editor'))->role;

        Assert::assertNotNull($found);
        Assert::assertTrue($found->id->equals($role));
        Assert::assertSame(ClassificationAccess::Internal, $found->ceiling);
        Assert::assertSame(['entry.create', 'grant.assign'], array_map(static fn (CommandName $name): string => $name->value, $found->permissions));
        Assert::assertNotNull($empty);
        Assert::assertSame([], $empty->permissions);
    }
}
