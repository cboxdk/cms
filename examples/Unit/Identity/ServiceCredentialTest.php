<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;

// An agent's service credential on the testkit's fake: it verifies to the agent on behalf of the
// editor, with the kind agent and its ceiling, until the editor is deactivated.

it('verifies an agent credential to its principal until the person it acts for is deactivated', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2031-05-01T09:00:00Z'));
    $identity = new FakeIdentity($clock);
    $agent = $identity->addActor(ActorClass::Service);
    $editor = $identity->addActor(ActorClass::Staff);

    $credential = $identity->issue(new ServiceCredentialSpec(
        $agent->id,
        IssuerKind::Agent,
        ClassificationAccess::Confidential,
        new DateTimeImmutable('2031-06-01T00:00:00Z'),
        [$editor->id],
    ));

    $principal = $identity->verifier()->verify($credential);

    expect($principal)->toBeInstanceOf(ActorPrincipal::class)
        ->and($principal instanceof ActorPrincipal ? $principal->issuerKind : null)->toBe(IssuerKind::Agent)
        ->and($principal->classificationCeiling())->toBe(ClassificationAccess::Confidential);

    // What the pipelines get: the principal, its regions and what it may read.
    $context = new AccessContext($principal, [new AccessRegion(new NodePath('site.news'))], ClassificationAccess::Internal);

    expect($context->reaches(new NodePath('site.news.local')))->toBeTrue()
        ->and($context->reaches(new NodePath('site.sport')))->toBeFalse();

    $identity->changeState($editor->id, ActorState::Deactivated);

    try {
        $identity->verifier()->verify($credential);
        $refused = null;
    } catch (CredentialRejected $rejected) {
        $refused = $rejected->reason;
    }

    expect($refused)->toBe(CredentialErrorCode::ActorNotActive);
});

it('gives a call without a credential the anonymous principal, which reads only public', function (): void {
    $principal = new FakeIdentity()->verifier()->verify(null);

    expect($principal)->toBeInstanceOf(AnonymousPrincipal::class)
        ->and(AccessContext::anonymous()->classificationAccess)->toBe(ClassificationAccess::Public)
        ->and($principal->classificationCeiling())->toBe(ClassificationAccess::Public);
});

it('refuses an agent credential that could read personal data', function (): void {
    $identity = new FakeIdentity;
    $agent = $identity->addActor(ActorClass::Service);

    expect(fn (): ServiceCredentialSpec => new ServiceCredentialSpec($agent->id, IssuerKind::Agent, ClassificationAccess::Personal, new DateTimeImmutable('2100-01-01T00:00:00Z')))
        ->toThrow(InvalidIdentity::class, 'at most confidential');
});
