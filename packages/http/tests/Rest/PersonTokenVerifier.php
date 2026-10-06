<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest;

use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialForm;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Override;

/**
 * A CredentialVerifier for the parity tests of a person on REST and in the panel (PRD 5.16, 13.4;
 * Sylvester 29 September: the panel and REST stay in parity): the Bearer token given verifies as
 * the person, with the principal a personal API token of part 2 of B1 gives a human, and every
 * other credential, the panel's session among them, goes to the verifier it decorates. In part 1 a
 * person has no credential of their own for REST, so this stands in for the token verifier that
 * comes with part 2.
 */
final readonly class PersonTokenVerifier implements CredentialVerifier
{
    public function __construct(
        private CredentialVerifier $verifier,
        private ActorId $person,
        private string $token,
    ) {}

    #[Override]
    public function verify(?TransportCredential $credential): Principal
    {
        if ($credential instanceof TransportCredential && $credential->form === CredentialForm::Bearer && hash_equals($this->token, $credential->reveal())) {
            return new ActorPrincipal($this->person, [], IssuerKind::Human, ClassificationAccess::Sensitive);
        }

        return $this->verifier->verify($credential);
    }
}
