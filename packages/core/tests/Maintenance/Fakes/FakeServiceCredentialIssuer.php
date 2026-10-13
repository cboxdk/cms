<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance\Fakes;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Maintenance\Domain\CredentialIssueRefused;
use Cbox\Cms\Core\Maintenance\Domain\ServiceCredentialIssuer;
use Override;

/**
 * The service credential issuer in memory, and the verifier of what it issued: a token of the real
 * form, kept as its hash with the credential, and verified through IssuedCredential::principal()
 * against the directory's current actor, as the Postgres verifier decides. Without the owner
 * connection it refuses as the Postgres issuer does. Every credential it issued is in $issued, by
 * the token's hash.
 */
final class FakeServiceCredentialIssuer implements CredentialVerifier, ServiceCredentialIssuer
{
    /** @var array<string, IssuedCredential> by the token's hash */
    public array $issued = [];

    public function __construct(
        private readonly ActorDirectory $actors,
        private readonly Clock $clock,
        private readonly bool $ownerConnection = true,
    ) {}

    #[Override]
    public function issue(IssuedCredential $credential): TransportCredential
    {
        if (! $this->ownerConnection) {
            throw CredentialIssueRefused::ownerConnectionMissing();
        }

        $token = ServiceCredentialToken::fromSecret(random_bytes(ServiceCredentialToken::SECRET_BYTES));
        $this->issued[$token->hash()] = $credential;

        return $token->credential();
    }

    #[Override]
    public function verify(?TransportCredential $credential): Principal
    {
        if (! $credential instanceof TransportCredential) {
            return new AnonymousPrincipal;
        }

        $token = ServiceCredentialToken::parse($credential);
        $issued = $this->issued[$token->hash()] ?? throw CredentialRejected::because(CredentialErrorCode::Unknown);
        $actor = $this->actors->find($issued->actor) ?? throw CredentialRejected::because(CredentialErrorCode::ActorNotActive);
        $chain = [];

        foreach ($issued->onBehalfOf as $id) {
            $chain[] = $this->actors->find($id) ?? throw CredentialRejected::because(CredentialErrorCode::ActorNotActive);
        }

        return $issued->principal($actor, $chain, $this->clock->now());
    }
}
