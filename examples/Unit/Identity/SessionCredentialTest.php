<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Testkit\Identity\FakeIdentity;

// A person's session on the testkit's fake: it verifies to the person as the issuer kind human,
// which a changeset records as human, a bearer token is never read as one, and it is refused once
// the person is deactivated.

it('verifies a session to the person who logged in until the person is deactivated', function (): void {
    $identity = new FakeIdentity;
    $editor = $identity->addActor(ActorClass::Staff);

    $session = $identity->startSession($editor->id);
    $principal = $identity->verifier()->verify($session);

    expect($principal)->toBeInstanceOf(ActorPrincipal::class)
        ->and($principal instanceof ActorPrincipal ? $principal->issuerKind : null)->toBe(IssuerKind::Human)
        ->and(IssuerKind::Human->envelopeIssuer())->toBe(EnvelopeIssuer::Human)
        ->and($principal->classificationCeiling())->toBe(ClassificationAccess::Sensitive);

    try {
        $identity->verifier()->verify(new TransportCredential($session->reveal()));
        $asBearer = null;
    } catch (CredentialRejected $rejected) {
        $asBearer = $rejected->reason;
    }

    $identity->changeState($editor->id, ActorState::Deactivated);

    try {
        $identity->verifier()->verify($session);
        $refused = null;
    } catch (CredentialRejected $rejected) {
        $refused = $rejected->reason;
    }

    expect($asBearer)->toBe(CredentialErrorCode::Malformed)
        ->and($refused)->toBe(CredentialErrorCode::ActorNotActive);
});
