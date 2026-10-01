<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Tests\Access\Fixtures\PrivateProbe;
use Cbox\Cms\Core\Tests\Access\Fixtures\PublicProbe;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every QueryAuthorizer does (PRD 5.10, 6.2, invariant 25), held against the fake the query
 * pipeline's tests use, FakeQueryAuthorizer::byGrants(), and the kernel's GrantQueryAuthorizer on
 * Postgres: a public read runs for anyone, any other read only for an actor whose permissions name
 * it, never for the anonymous principal.
 */
trait QueryAuthorizerBehaviour
{
    abstract protected function queryAuthorizer(): QueryAuthorizer;

    abstract protected function actor(): ActorPrincipal;

    /**
     * Reads the actor's permissions name.
     *
     * @return list<CommandName>
     */
    abstract protected function permittedQueries(): array;

    /**
     * Reads the actor may not run, by why.
     *
     * @return array<string, CommandName>
     */
    abstract protected function refusedQueries(): array;

    /**
     * Begins the transaction the authorizer runs in, with the principal's context set.
     */
    abstract protected function begin(Principal $principal): AccessContext;

    abstract protected function end(): void;

    #[Test]
    public function it_runs_a_public_read_for_anyone(): void
    {
        $name = new CommandName('probe.public');

        Assert::assertTrue($this->decided(new AnonymousPrincipal, $name, new PublicProbe)->allowed());
        Assert::assertTrue($this->decided($this->actor(), $name, new PublicProbe)->allowed());
    }

    #[Test]
    public function it_refuses_the_anonymous_principal_every_other_read(): void
    {
        foreach ($this->permittedQueries() as $query) {
            Assert::assertFalse($this->decided(new AnonymousPrincipal, $query, new PrivateProbe)->allowed(), $query->value);
        }
    }

    #[Test]
    public function it_runs_a_read_the_actor_s_permissions_name(): void
    {
        foreach ($this->permittedQueries() as $query) {
            Assert::assertTrue($this->decided($this->actor(), $query, new PrivateProbe)->allowed(), $query->value);
        }
    }

    #[Test]
    public function it_refuses_a_read_the_actor_s_permissions_do_not_allow(): void
    {
        foreach ($this->refusedQueries() as $case => $query) {
            $decision = $this->decided($this->actor(), $query, new PrivateProbe);

            Assert::assertFalse($decision->allowed(), $case);
            Assert::assertNotSame('', trim((string) $decision->reason), $case);
        }
    }

    private function decided(Principal $principal, CommandName $name, Query $query): Authorization
    {
        $access = $this->begin($principal);

        try {
            return $this->queryAuthorizer()->authorize($access, $name, $query);
        } finally {
            $this->end();
        }
    }
}
