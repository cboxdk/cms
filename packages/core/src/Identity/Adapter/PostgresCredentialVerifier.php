<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialForm;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * The credential verifier on Postgres (PRD 5.16, 6.2), as the app role.
 *
 * A token is parsed first: one that is not in the form of ServiceCredentialToken, or whose checksum
 * does not match, is refused before any statement runs. It holds no sessions: a session id in the
 * form of SessionToken is credential_unknown here, and the identity module decorates the bound
 * verifier with the one that holds them. Then it reads the credential by the
 * SHA-256 of the token together with its actor, and the actors of its on-behalf-of chain in order,
 * both through the lookup functions `cms_identity_credential`, `cms_identity_delegations` and
 * `cms_identity_actor` on the write PDO, so a read host that lags never hides a deactivation or a
 * revocation. The identity tables give the app role no row without an actor context, and the
 * verifier runs before one exists (PRD 6.2), so the lookups run as the owner role and return only
 * the rows of the one token asked for; see the access migration. IssuedCredential::principal() decides the rest at the Clock's
 * time. It never writes and never begins a transaction.
 */
#[Experimental]
final readonly class PostgresCredentialVerifier implements CredentialVerifier
{
    public const string CREDENTIALS = 'service_credentials';

    public const string DELEGATIONS = 'service_credential_delegations';

    /** The lookup of one credential by the hash of its token, as the owner role. */
    public const string CREDENTIAL_LOOKUP = 'cms_identity_credential';

    /** The lookup of one credential's on-behalf-of chain, as the owner role. */
    public const string DELEGATION_LOOKUP = 'cms_identity_delegations';

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

        if ($credential->form === CredentialForm::Session) {
            // The core holds no sessions: the identity module decorates this verifier with the
            // session store. A session id in its form is one this verifier does not have.
            SessionToken::parse($credential);

            throw CredentialRejected::because(CredentialErrorCode::Unknown);
        }

        $token = ServiceCredentialToken::parse($credential);
        $db = $this->db();

        $row = $db->table(self::CREDENTIALS)
            ->fromRaw(sprintf('%s(?) as c cross join lateral %s(c.actor_id) as a', self::CREDENTIAL_LOOKUP, PostgresActorDirectory::LOOKUP), [$token->hash()])
            ->useWritePdo()
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

        foreach ($db->table(self::DELEGATIONS)
            ->fromRaw(sprintf('%s(?) as d cross join lateral %s(d.actor_id) as a', self::DELEGATION_LOOKUP, PostgresActorDirectory::LOOKUP), [IdentityRows::string($row, 'id', self::CREDENTIALS)])
            ->useWritePdo()
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
