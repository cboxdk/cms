<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Adapter\GrantRows;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;
use Cbox\Cms\Core\Identity\Domain\Dto\OwnGrant;
use Cbox\Cms\Core\Identity\Domain\OwnActorReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * OwnActorReader on Postgres (PRD 5.10, 5.16), on the default connection, or the one named, inside
 * the read transaction and under its actor context, on the write PDO. The actor is the context's,
 * `cms_access_actor()`, so the read can give nothing but the reader's own self: the actor as the
 * ActorDirectory reads it, its profile through the policy `actor_profiles_own`, and its grants
 * that have not ended with their roles' handles, which the app role reads for its own actor
 * (`grants`) and for every actor (`roles`). Without an actor context there is no actor, and own()
 * gives null.
 */
#[Internal]
final readonly class PostgresOwnActorReader implements OwnActorReader
{
    /** The actor of the context, or null without one. */
    public const string ACTOR = 'select cms_access_actor() as actor';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ActorDirectory $actors,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function own(): ?ActorMe
    {
        $context = $this->db()->selectOne(self::ACTOR, [], false);
        $id = $context === null ? null : GrantRows::text(GrantRows::row($context), 'actor', nullable: true);

        if ($id === null) {
            return null;
        }

        $actor = $this->actors->find(ActorId::fromString($id));

        if (! $actor instanceof Actor) {
            return null;
        }

        return new ActorMe($actor->id, $actor->class, $actor->state, new AggregateVersion($actor->version), $this->profile($actor->id), $this->grants($actor->id));
    }

    private function profile(ActorId $actor): ?ActorProfile
    {
        $row = $this->db()->table('actor_profiles')->useWritePdo()->where('actor_id', $actor->toString())->first(['display_name', 'email']);

        if ($row === null) {
            return null;
        }

        $row = GrantRows::row($row);

        return new ActorProfile(new DisplayName(GrantRows::text($row, 'display_name')), new EmailAddress(GrantRows::text($row, 'email')));
    }

    /**
     * @return list<OwnGrant>
     */
    private function grants(ActorId $actor): array
    {
        $rows = $this->db()->table('grants as g')
            ->useWritePdo()
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->where('g.actor_id', $actor->toString())
            ->whereNull('g.ended_changeset_id')
            ->orderBy('g.id')
            ->get(['g.id', 'g.role_id', 'r.handle', 'g.node_id', 'g.effect', 'g.locales', 'g.version']);
        $grants = [];

        foreach ($rows as $row) {
            $row = GrantRows::row($row);
            $grants[] = new OwnGrant(
                GrantId::fromString(GrantRows::text($row, 'id')),
                RoleId::fromString(GrantRows::text($row, 'role_id')),
                new RoleHandle(GrantRows::text($row, 'handle')),
                NodeId::fromString(GrantRows::text($row, 'node_id')),
                GrantEffect::from(GrantRows::text($row, 'effect')),
                GrantRows::locales($row, 'locales'),
                new AggregateVersion(GrantRows::integer($row, 'version')),
            );
        }

        return $grants;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
