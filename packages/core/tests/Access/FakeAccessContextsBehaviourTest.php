<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AccessContextsBehaviour against the fake the surface tests use, and what the fake adds: the
 * grants a test gives an actor, capped by the credential's ceiling, and the principals it was
 * asked for.
 */
final class FakeAccessContextsBehaviourTest extends TestCase
{
    use AccessContextsBehaviour;

    private ?FakeAccessContexts $contexts = null;

    #[Override]
    protected function accessContexts(): AccessContexts
    {
        return $this->contexts ??= new FakeAccessContexts;
    }

    #[Test]
    public function a_granted_actor_gets_its_regions_and_access_capped_by_its_ceiling(): void
    {
        $actor = ActorId::fromString(self::UNGRANTED);
        $region = new AccessRegion(new NodePath('a1.b2'), []);
        $contexts = new FakeAccessContexts()->grant($actor, ClassificationAccess::Sensitive, $region);
        $principal = new ActorPrincipal($actor, [], IssuerKind::Agent, ClassificationAccess::Confidential);

        self::assertEquals(new AccessContext($principal, [$region], ClassificationAccess::Confidential), $contexts->for($principal));
        self::assertSame([$principal], $contexts->asked);
    }

    #[Test]
    public function it_records_the_anonymous_principal_too(): void
    {
        $contexts = new FakeAccessContexts;
        $anonymous = new AnonymousPrincipal;

        $contexts->for($anonymous);

        self::assertSame([$anonymous], $contexts->asked);
    }
}
