<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Adapter\GrantRows;
use Cbox\Cms\Core\Identity\Domain\ActorListing;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedActor;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedProfile;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * ActorListing on Postgres (PRD 5.16, 12.2), on the default connection, or the one named, inside
 * the read transaction and under its actor context, on the write PDO. The app role reads no actor,
 * so a page is one statement through the owner function cms_identity_actor_list, which gives the
 * profiles the context may read.
 */
#[Internal]
final readonly class PostgresActorListing implements ActorListing
{
    /** A page of staff actors, as the owner role. */
    public const string STAFF = 'select id, state, version, display_name, email from cms_identity_actor_list(?::uuid, ?)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function staff(?ActorId $after, int $limit): array
    {
        $actors = [];

        foreach ($this->db()->select(self::STAFF, [$after?->toString(), $limit], false) as $row) {
            $row = GrantRows::row($row);
            $name = GrantRows::text($row, 'display_name', nullable: true);
            $email = GrantRows::text($row, 'email', nullable: true);
            $actors[] = new ListedActor(
                ActorId::fromString(GrantRows::text($row, 'id')),
                ActorState::from(GrantRows::text($row, 'state')),
                new AggregateVersion(GrantRows::integer($row, 'version')),
                $name === null || $email === null ? null : new ListedProfile(new DisplayName($name), new EmailAddress($email)),
            );
        }

        return $actors;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
