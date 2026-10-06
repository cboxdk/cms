<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Access\Domain\OwnHeldGrants;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * OwnHeldGrants on Postgres (PRD 5.10, 13.4), on the default connection, or the one named, inside
 * the read transaction and under its actor context, on the write PDO. The actor is the context's,
 * `cms_access_actor()`, so the read can give nothing but the reader's own grants: those that have
 * not ended, with their roles' ceilings and nodes' paths and each role's permissions, as
 * PostgresGrants::held() reads them for the escalation guard. Without an actor context there is no
 * actor, and held() gives none.
 */
#[Internal]
final readonly class PostgresOwnHeldGrants implements OwnHeldGrants
{
    /** The actor of the context, or null without one. */
    public const string ACTOR = 'select cms_access_actor() as actor';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function held(): array
    {
        $context = $this->connections->connection($this->connection)->selectOne(self::ACTOR, [], false);
        $actor = $context === null ? null : GrantRows::text(GrantRows::row($context), 'actor', nullable: true);

        if ($actor === null) {
            return [];
        }

        return new PostgresGrants($this->connections, $this->connection)->held(ActorId::fromString($actor));
    }
}
