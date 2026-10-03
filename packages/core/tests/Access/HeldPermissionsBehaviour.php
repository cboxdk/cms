<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every HeldPermissions does (PRD 5.10, 13.4), held against the fake the panel's action tests
 * use and the adapter on real Postgres, over a world where the granted actor holds a role that may
 * run READ and WRITE, allowed on a node, and a role that may run AUDIT, denied on the same node,
 * with internal access; the ungranted actor holds nothing; and the delegate holds a role that may
 * run READ and AUDIT everywhere, with a credential issued on behalf of the granted actor.
 */
trait HeldPermissionsBehaviour
{
    public const string READ = 'probe.read';

    public const string WRITE = 'probe.write';

    public const string AUDIT = 'probe.audit';

    public const string OTHER = 'probe.other';

    abstract protected function heldPermissions(): HeldPermissions;

    abstract protected function granted(): ActorPrincipal;

    abstract protected function ungranted(): ActorPrincipal;

    /** The delegate acting alone. */
    abstract protected function delegate(): ActorPrincipal;

    /** The delegate on behalf of the granted actor. */
    abstract protected function delegated(): ActorPrincipal;

    #[Test]
    public function the_anonymous_principal_holds_nothing_and_gets_the_anonymous_context(): void
    {
        $held = $this->heldPermissions()->of(new AnonymousPrincipal, self::names(self::READ, self::WRITE));

        Assert::assertEquals(AccessContext::anonymous(), $held->access);
        Assert::assertSame([], $held->held);
    }

    #[Test]
    public function an_actor_holds_what_a_role_allowed_on_a_node_permits_each_once_and_sorted(): void
    {
        $held = $this->heldPermissions()->of($this->granted(), self::names(self::WRITE, self::OTHER, self::READ, self::WRITE));

        Assert::assertSame([self::READ, self::WRITE], self::values($held->held));
        Assert::assertTrue($held->holds(new CommandName(self::READ)));
        Assert::assertFalse($held->holds(new CommandName(self::OTHER)));
        Assert::assertSame($this->granted(), $held->access->principal);
        Assert::assertSame(ClassificationAccess::Internal, $held->access->classificationAccess);
    }

    #[Test]
    public function a_role_denied_on_its_only_node_gives_nothing(): void
    {
        Assert::assertSame([], $this->heldPermissions()->of($this->granted(), self::names(self::AUDIT))->held);
    }

    #[Test]
    public function an_actor_without_grants_holds_nothing_with_public_access(): void
    {
        $held = $this->heldPermissions()->of($this->ungranted(), self::names(self::READ, self::WRITE, self::AUDIT));

        Assert::assertSame([], $held->held);
        Assert::assertSame(ClassificationAccess::Public, $held->access->classificationAccess);
    }

    #[Test]
    public function asking_for_no_name_gives_the_context_and_nothing_held(): void
    {
        $held = $this->heldPermissions()->of($this->granted(), []);

        Assert::assertSame([], $held->held);
        Assert::assertSame(ClassificationAccess::Internal, $held->access->classificationAccess);
    }

    #[Test]
    public function an_actor_on_behalf_of_a_person_holds_only_what_both_hold(): void
    {
        Assert::assertSame([self::AUDIT, self::READ], self::values($this->heldPermissions()->of($this->delegate(), self::names(self::READ, self::WRITE, self::AUDIT))->held));
        Assert::assertSame([self::READ], self::values($this->heldPermissions()->of($this->delegated(), self::names(self::READ, self::WRITE, self::AUDIT))->held));
    }

    /**
     * @return list<CommandName>
     */
    private static function names(string ...$names): array
    {
        return array_values(array_map(static fn (string $name): CommandName => new CommandName($name), $names));
    }

    /**
     * @param  list<CommandName>  $names
     * @return list<string>
     */
    private static function values(array $names): array
    {
        return array_map(static fn (CommandName $name): string => $name->value, $names);
    }
}
