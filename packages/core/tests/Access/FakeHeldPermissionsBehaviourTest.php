<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HeldPermissionsBehaviour against the fake the panel's action tests use, and what the fake adds:
 * the principals and names it was asked for.
 */
final class FakeHeldPermissionsBehaviourTest extends TestCase
{
    use HeldPermissionsBehaviour;

    private const string GRANTED = '0192a0c0-0000-7000-8000-000000000a01';

    private const string UNGRANTED = '0192a0c0-0000-7000-8000-000000000a02';

    private const string DELEGATE = '0192a0c0-0000-7000-8000-000000000a03';

    private const string NODE = 'a1';

    private ?FakeHeldPermissions $held = null;

    /** @var array<string, ActorPrincipal> each principal once, so the behaviour can compare them by identity */
    private array $principals = [];

    #[Override]
    protected function heldPermissions(): HeldPermissions
    {
        if ($this->held instanceof FakeHeldPermissions) {
            return $this->held;
        }

        $node = new NodePath(self::NODE);
        $permissions = new FakePermissions([])
            ->grant(ActorId::fromString(self::GRANTED), new Grant(RoleId::fromString('0192a0c0-0000-7000-8000-000000000a11'), ClassificationAccess::Internal, $node, GrantEffect::Allow), self::names(self::READ, self::WRITE))
            ->grant(ActorId::fromString(self::GRANTED), new Grant(RoleId::fromString('0192a0c0-0000-7000-8000-000000000a12'), ClassificationAccess::Internal, $node, GrantEffect::Deny), self::names(self::AUDIT))
            ->grant(ActorId::fromString(self::DELEGATE), new Grant(RoleId::fromString('0192a0c0-0000-7000-8000-000000000a13'), ClassificationAccess::Sensitive, $node, GrantEffect::Allow), self::names(self::READ, self::AUDIT));

        return $this->held = new FakeHeldPermissions($permissions, new FakeAccessContexts()->grant(ActorId::fromString(self::GRANTED), ClassificationAccess::Internal));
    }

    #[Override]
    protected function granted(): ActorPrincipal
    {
        return $this->principal(self::GRANTED);
    }

    #[Override]
    protected function ungranted(): ActorPrincipal
    {
        return $this->principal(self::UNGRANTED);
    }

    #[Override]
    protected function delegate(): ActorPrincipal
    {
        return $this->principal(self::DELEGATE);
    }

    #[Override]
    protected function delegated(): ActorPrincipal
    {
        return $this->principal(self::DELEGATE, self::GRANTED);
    }

    #[Test]
    public function it_records_what_it_was_asked(): void
    {
        $held = $this->heldPermissions();
        $anonymous = new AnonymousPrincipal;
        $held->of($anonymous, self::names(self::READ));

        self::assertInstanceOf(FakeHeldPermissions::class, $held);
        self::assertSame($anonymous, $held->asked[0][0]);
        self::assertSame(self::READ, $held->asked[0][1][0]->value);
    }

    private function principal(string $actor, string ...$onBehalfOf): ActorPrincipal
    {
        return $this->principals[$actor.'/'.implode('/', $onBehalfOf)] ??= new ActorPrincipal(ActorId::fromString($actor), array_values(array_map(ActorId::fromString(...), $onBehalfOf)), IssuerKind::Service, ClassificationAccess::Sensitive);
    }
}
