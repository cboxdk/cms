<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * The credential verifier on Postgres (PRD 5.16, 6.2), as the app role.
 *
 * A token is parsed first: one that is not in the form of ServiceCredentialToken, or whose checksum
 * does not match, is refused before any statement runs. Then it reads the credential by the
 * SHA-256 of the token together with its actor, and the actors of its on-behalf-of chain in order,
 * both through the query builder on the write PDO, so a read host that lags never hides a
 * deactivation or a revocation. IssuedCredential::principal() decides the rest at the Clock's
 * time. It never writes and never begins a transaction.
 */
#[Experimental]
final readonly class PostgresCredentialVerifier implements CredentialVerifier
{
    public const string CREDENTIALS = 'service_credentials';

    public const string DELEGATIONS = 'service_credential_delegations';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $connection = null,
    ) {}

    public function verify(?TransportCredential $credential): Principal
    {
        if (! $credential instanceof TransportCredential) {
            return new AnonymousPrincipal;
        }

        $token = ServiceCredentialToken::parse($credential);
        $db = $this->db();

        $row = $db->table(self::CREDENTIALS.' as c')
            ->useWritePdo()
            ->join(PostgresActorDirectory::TABLE.' as a', 'a.id', '=', 'c.actor_id')
            ->where('c.secret_hash', $token->hash())
            ->first([
                'c.id',
                'c.credential_generation',
                'c.issuer_kind',
                'c.classification_ceiling',
                'c.expires_at',
                ...IdentityRows::actorColumns('a'),
            ]);

        if ($row === null) {
            throw CredentialRejected::because(CredentialErrorCode::Unknown);
        }

        $chain = [];

        foreach ($db->table(self::DELEGATIONS.' as d')
            ->useWritePdo()
            ->join(PostgresActorDirectory::TABLE.' as a', 'a.id', '=', 'd.actor_id')
            ->where('d.credential_id', IdentityRows::string($row, 'id', self::CREDENTIALS))
            ->orderBy('d.position')
            ->get(IdentityRows::actorColumns('a')) as $link) {
            $chain[] = IdentityRows::actor($link, self::DELEGATIONS);
        }

        $issued = IdentityRows::credential(
            $row,
            array_map(static fn (Actor $actor): ActorId => $actor->id, $chain),
            self::CREDENTIALS,
        );

        return $issued->principal(IdentityRows::actor($row, self::CREDENTIALS), $chain, $this->clock->now());
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
