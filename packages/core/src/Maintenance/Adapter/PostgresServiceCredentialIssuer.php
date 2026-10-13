<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Identity\Adapter\PostgresCredentialVerifier;
use Cbox\Cms\Core\Maintenance\Domain\CredentialIssueRefused;
use Cbox\Cms\Core\Maintenance\Domain\ServiceCredentialIssuer;
use Cbox\Cms\Core\Partitions\Boundary\SqlError;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Override;

/**
 * The service credential issuer on Postgres (PRD 5.16), as the owner role on the owner connection:
 * the write policy of `service_credentials` is the owner's alone, so the app role, which the
 * credential verifier reads as, cannot issue one. It makes the token from 32 bytes of a secure
 * random source, inserts the credential's row with the SHA-256 of the token, the actor, the
 * generation, the issuer kind, the ceiling and the expiry, at the Clock's time, and gives the token
 * back. A credential of the maintenance process acts on behalf of no one, so it writes no
 * delegation.
 *
 * Without an owner connection it refuses with maintenance_process_required before any statement,
 * and so does a connection whose role Postgres refuses the insert (42501), which is then not the
 * owner role's.
 */
#[Internal]
final readonly class PostgresServiceCredentialIssuer implements ServiceCredentialIssuer
{
    /** insufficient_privilege: the connection's role may not write a credential. */
    private const string NOT_OWNER = '42501';

    /**
     * @param  string|null  $ownerConnection  the owner role's connection; null when this process has none
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private IdGenerator $ids,
        private ?string $ownerConnection,
    ) {}

    #[Override]
    public function issue(IssuedCredential $credential): TransportCredential
    {
        $name = $this->ownerConnection ?? throw CredentialIssueRefused::ownerConnectionMissing();
        $token = ServiceCredentialToken::fromSecret(random_bytes(ServiceCredentialToken::SECRET_BYTES));

        try {
            $this->connections->connection($name)->table(PostgresCredentialVerifier::CREDENTIALS)->insert([
                'id' => $this->ids->next()->value,
                'actor_id' => $credential->actor->toString(),
                'secret_hash' => $token->hash(),
                'credential_generation' => $credential->generation->value,
                'issuer_kind' => $credential->issuerKind->value,
                'classification_ceiling' => $credential->ceiling->value,
                'expires_at' => Timestamps::of($credential->expiresAt),
                'created_at' => Timestamps::of($this->clock->now()),
            ]);
        } catch (QueryException $exception) {
            if (SqlError::of($exception)->is(self::NOT_OWNER)) {
                throw CredentialIssueRefused::notOwner($name);
            }

            throw $exception;
        }

        return $token->credential();
    }
}
