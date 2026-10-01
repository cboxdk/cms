<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every QueryAuthorizer of the kernel does (PRD 5.10, 6.2, invariant 25), held against the
 * fake the query pipeline's tests use and the PostgresQueryAuthorizer: a public read, path.resolve,
 * is allowed for anyone, the anonymous principal included; any other read only for an actor with a
 * role whose permissions name it and that reaches some node.
 */
trait QueryAuthorizerBehaviour
{
    /**
     * A new active actor holding exactly the grants, as CommandAuthorizerBehaviour's.
     *
     * @param  list<array{string, list<string>, string, GrantEffect, list<string>|null}>  $grants
     */
    abstract protected function grantedActor(array $grants): ActorPrincipal;

    abstract protected function queryAuthorizer(): QueryAuthorizer;

    /**
     * Runs the authorization with the principal's access context, inside its read transaction with
     * the context set.
     *
     * @param  Closure(AccessContext): Authorization  $authorize
     */
    abstract protected function within(Principal $principal, Closure $authorize): Authorization;

    #[Test]
    public function it_allows_a_public_read_for_anyone(): void
    {
        $actor = $this->grantedActor([]);

        Assert::assertTrue($this->authorized(new AnonymousPrincipal, 'path.resolve', $this->resolve())->allowed());
        Assert::assertTrue($this->authorized($actor, 'path.resolve', $this->resolve())->allowed());
    }

    #[Test]
    public function it_refuses_any_other_read_to_the_anonymous_principal(): void
    {
        $refusal = $this->authorized(new AnonymousPrincipal, 'probe.read', new ReadProbe);

        Assert::assertFalse($refusal->allowed());
        Assert::assertStringContainsString('probe.read', (string) $refusal->reason);
    }

    #[Test]
    public function it_allows_a_read_only_through_a_role_whose_permissions_name_it(): void
    {
        $reader = $this->grantedActor([['reader', ['probe.read'], AccessWorld::NEWS, GrantEffect::Allow, ['da']]]);

        Assert::assertTrue($this->authorized($reader, 'probe.read', new ReadProbe)->allowed());
    }

    #[Test]
    public function it_refuses_a_read_no_role_of_the_actor_names_or_that_reaches_no_node(): void
    {
        $actor = $this->grantedActor([
            ['writer', ['entry.create'], AccessWorld::NEWS, GrantEffect::Allow, null],
            ['revoked', ['probe.read'], AccessWorld::CULTURE, GrantEffect::Deny, null],
        ]);

        Assert::assertFalse($this->authorized($actor, 'probe.read', new ReadProbe)->allowed());
    }

    private function authorized(Principal $principal, string $query, Query $input): Authorization
    {
        return $this->within($principal, fn (AccessContext $access): Authorization => $this->queryAuthorizer()->authorize($access, new CommandName($query), $input));
    }

    private function resolve(): ResolvePath
    {
        return new ResolvePath(new Host('north.example'), new Locale('da'), new RequestPath('/'));
    }
}
