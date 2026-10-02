<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * The IdP links of the identity store, the table cms_identity.idp_links, read on the identity
 * role's connection, cbox-cms.identity.connection (PRD 5.16, "Koblinger"). The app role cannot read
 * the table. The rows are written by the federated connections and SCIM, which come with B1 part 2.
 */
#[Internal]
final readonly class PostgresIdpLinks implements IdpLinks
{
    public const string TABLE = 'idp_links';

    public function __construct(
        private DatabaseManager $database,
        private ?string $connection,
    ) {}

    public function connectionsOf(ActorId $actor): array
    {
        if ($this->connection === null) {
            throw new LogicException('cbox-cms.identity.connection names no database connection, so the IdP links cannot be read; cms:doctor reports it as identity.connection.');
        }

        $names = $this->database->connection($this->connection)
            ->table(CredentialStore::table(self::TABLE))
            ->where('actor_id', $actor->toString())
            ->distinct()
            ->orderBy('connection')
            ->pluck('connection')
            ->all();

        return array_values(array_map(static fn (mixed $name): ConnectionId => new ConnectionId(is_string($name) ? $name : ''), $names));
    }
}
