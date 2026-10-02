<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\LoginPolicy\Fakes;

use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;

/**
 * The IdP links in memory, held to PostgresIdpLinks by IdpLinksBehaviour. link() records that an
 * IdP identity points at an actor, as a federated connection or SCIM would write it, and reads()
 * counts the lookups, so a test can see that the policy read the links.
 */
final class FakeIdpLinks implements IdpLinks
{
    /** @var array<string, array{actor: ActorId, connection: ConnectionId}> by the IdP identity */
    private array $links = [];

    private int $reads = 0;

    public function link(ActorId $actor, IdpIdentity $identity): void
    {
        $key = $identity->connection->value."\n".$identity->issuer->value."\n".$identity->subject->value;
        $this->links[$key] = ['actor' => $actor, 'connection' => $identity->connection];
    }

    public function connectionsOf(ActorId $actor): array
    {
        $this->reads++;
        $names = [];

        foreach ($this->links as $link) {
            if ($link['actor']->equals($actor)) {
                $names[$link['connection']->value] = $link['connection'];
            }
        }

        ksort($names, SORT_STRING);

        return array_values($names);
    }

    public function reads(): int
    {
        return $this->reads;
    }
}
