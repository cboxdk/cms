<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every AccessContexts does (PRD 5.10, 6.2), held against the fake the surface tests use and
 * the adapter on real Postgres: the anonymous principal gets the anonymous context, an actor
 * without grants gets its own principal, chain included, with public access and no regions, and
 * asking twice gives the same context.
 */
trait AccessContextsBehaviour
{
    /** An actor that holds no grant. */
    public const string UNGRANTED = '0192a0c0-0000-7000-8000-0000000000c9';

    /** The actor the ungranted actor acts on behalf of. */
    public const string PERSON = '0192a0c0-0000-7000-8000-0000000000ca';

    abstract protected function accessContexts(): AccessContexts;

    #[Test]
    public function the_anonymous_principal_gets_the_anonymous_context(): void
    {
        Assert::assertEquals(AccessContext::anonymous(), $this->accessContexts()->for(new AnonymousPrincipal));
    }

    #[Test]
    public function an_actor_without_grants_gets_its_principal_with_public_access_and_no_regions(): void
    {
        $principal = new ActorPrincipal(ActorId::fromString(self::UNGRANTED), [ActorId::fromString(self::PERSON)], IssuerKind::Agent, ClassificationAccess::Confidential);

        $context = $this->accessContexts()->for($principal);

        Assert::assertSame($principal, $context->principal);
        Assert::assertSame([], $context->regions);
        Assert::assertSame(ClassificationAccess::Public, $context->classificationAccess);
    }

    #[Test]
    public function asking_twice_gives_the_same_context(): void
    {
        $principal = new ActorPrincipal(ActorId::fromString(self::UNGRANTED), [], IssuerKind::Service, ClassificationAccess::Sensitive);

        Assert::assertEquals($this->accessContexts()->for($principal), $this->accessContexts()->for($principal));
    }
}
