<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every AccessResolver does (PRD 5.10, 6.2), held against the fake the query pipeline's action
 * tests use and the Postgres resolver: inside a transaction it gives the anonymous principal the
 * anonymous context, an actor the regions of its grants with the classification its roles allow,
 * capped by the credential's ceiling, and an actor without grants no regions and public access;
 * outside one it refuses.
 */
trait AccessResolverBehaviour
{
    abstract protected function accessResolver(): AccessResolver;

    /**
     * An actor whose grants give it grantedRegions() and internal classification access.
     */
    abstract protected function grantedActor(): ActorId;

    /**
     * @return list<AccessRegion>
     */
    abstract protected function grantedRegions(): array;

    /**
     * An actor without grants.
     */
    abstract protected function ungrantedActor(): ActorId;

    abstract protected function begin(): void;

    abstract protected function end(): void;

    #[Test]
    public function it_gives_the_anonymous_principal_the_anonymous_context(): void
    {
        Assert::assertEquals(AccessContext::anonymous(), $this->resolved(new AnonymousPrincipal));
    }

    #[Test]
    public function it_gives_an_actor_the_regions_and_classification_of_its_grants(): void
    {
        $principal = new ActorPrincipal($this->grantedActor(), [], IssuerKind::Service, ClassificationAccess::Sensitive);
        $context = $this->resolved($principal);

        Assert::assertSame($principal, $context->principal);
        Assert::assertEquals($this->grantedRegions(), $context->regions);
        Assert::assertSame(ClassificationAccess::Internal, $context->classificationAccess);
    }

    #[Test]
    public function it_caps_the_classification_at_the_credential_s_ceiling(): void
    {
        $context = $this->resolved(new ActorPrincipal($this->grantedActor(), [], IssuerKind::Service, ClassificationAccess::Public));

        Assert::assertEquals($this->grantedRegions(), $context->regions);
        Assert::assertSame(ClassificationAccess::Public, $context->classificationAccess);
    }

    #[Test]
    public function it_gives_an_actor_without_grants_no_regions_and_public_access(): void
    {
        $context = $this->resolved(new ActorPrincipal($this->ungrantedActor(), [], IssuerKind::Service, ClassificationAccess::Sensitive));

        Assert::assertSame([], $context->regions);
        Assert::assertSame(ClassificationAccess::Public, $context->classificationAccess);
    }

    #[Test]
    public function it_refuses_to_resolve_outside_a_transaction(): void
    {
        $this->expectException(TransactionRequired::class);

        $this->accessResolver()->resolve(new AnonymousPrincipal);
    }

    private function resolved(AnonymousPrincipal|ActorPrincipal $principal): AccessContext
    {
        $this->begin();

        try {
            return $this->accessResolver()->resolve($principal);
        } finally {
            $this->end();
        }
    }
}
