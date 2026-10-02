<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\LoginPolicy;

use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every IdpLinks does, run against PostgresIdpLinks and FakeIdpLinks (GUARDRAILS 9): it gives
 * the connections an actor is linked to through its IdP identities, each once and sorted by name,
 * and nothing for an actor without links or for another actor's links.
 */
trait IdpLinksBehaviour
{
    abstract protected function links(): IdpLinks;

    /** An actor that exists, so a link can point at it. */
    abstract protected function newActor(): ActorId;

    abstract protected function link(ActorId $actor, IdpIdentity $identity): void;

    #[Test]
    public function it_gives_the_connections_an_actor_is_linked_to_each_once_and_sorted(): void
    {
        $actor = $this->newActor();
        $other = $this->newActor();

        $this->link($actor, self::identity('google', 'https://accounts.google.com', 'a-1'));
        $this->link($actor, self::identity('entra', 'https://login.microsoftonline.com/acme/v2.0', 'b-1'));
        $this->link($actor, self::identity('entra', 'https://login.microsoftonline.com/acme/v2.0', 'b-2'));
        $this->link($other, self::identity('okta', 'https://acme.okta.com', 'c-1'));

        Assert::assertSame(['entra', 'google'], self::names($this->links()->connectionsOf($actor)));
        Assert::assertSame(['okta'], self::names($this->links()->connectionsOf($other)));
    }

    #[Test]
    public function it_gives_nothing_for_an_actor_without_links(): void
    {
        $actor = $this->newActor();
        $this->link($this->newActor(), self::identity('google', 'https://accounts.google.com', 'a-1'));

        Assert::assertSame([], $this->links()->connectionsOf($actor));
    }

    private static function identity(string $connection, string $issuer, string $subject): IdpIdentity
    {
        return new IdpIdentity(new ConnectionId($connection), new Issuer($issuer), new Subject($subject));
    }

    /**
     * @param  list<ConnectionId>  $connections
     * @return list<string>
     */
    private static function names(array $connections): array
    {
        return array_map(static fn (ConnectionId $connection): string => $connection->value, $connections);
    }
}
