---
title: Credential verifier
weight: 37
description: "The CredentialVerifier contract: turn a transport credential into a Principal, the anonymous principal, service credentials with a checksum, issuer kinds and classification ceilings, the AccessContext, the Postgres verifier and the shared suite CredentialVerifierContract."
---

# Credential verifier

<!-- extension-point: Cbox\Cms\Contracts\Identity\CredentialVerifier -->
<!-- extension-point: Cbox\Cms\Contracts\Identity\Principal -->
<!-- extension-point: Cbox\Cms\Testkit\Identity\CredentialVerifierContract -->

Every command and every read starts by finding out who it runs as (PRD 5.16, 6.2). `Cbox\Cms\Contracts\Identity\CredentialVerifier` turns the credential the transport carried, such as the bearer token of an `Authorization` header, into a `Principal`. The actors themselves are read through the [actor directory](actor-directory.md).

## The contract

The verifier has one method, `verify(?TransportCredential $credential): Principal`. A `TransportCredential` holds the credential as it arrived; it is a secret, kept out of stack traces and dumps, and read only with `reveal()`.

A `Principal` is one of two final readonly classes, and nothing else implements the interface:

| Principal | When | What it holds |
|---|---|---|
| `AnonymousPrincipal` | the call carried no credential | nothing; its classification ceiling is `public`. It serves public reads and public writes such as submissions and registrations (invariant 25) |
| `ActorPrincipal` | the credential verified | `actor`, the `ActorId`; `onBehalfOf`, the actors it acts for in order, from the one it acts for directly; `issuerKind`; and `ceiling`, the credential's classification ceiling |

A credential that is given is verified, and never falls back to anonymous. A verifier refuses it by throwing `CredentialRejected`, whose `reason` is a `CredentialErrorCode`. The verifier checks in this order and gives the first reason that applies:

| Reason | Code | When |
|---|---|---|
| `Malformed` | `credential_malformed` | not in the form of a credential, or its checksum does not match; found without a lookup |
| `Unknown` | `credential_unknown` | no credential has it |
| `Expired` | `credential_expired` | its expiry is not after the `Clock`'s time |
| `ActorNotActive` | `actor_not_active` | its actor, or any actor in its on-behalf-of chain, is not active |
| `Revoked` | `credential_revoked` | its generation is lower than its actor's |

Each code is in the [error reference](../../reference/errors.md). A deactivation, a deprovisioning and `actor.credentials_revoke` count the actor's credential generation up, so everything it holds is refused at once, without a clock that could differ between pods. A verifier reads the current state from the primary, so a change that committed is seen by the next verification.

### Service credentials

A service credential belongs to a service actor. On the wire it is `ServiceCredentialToken`: the prefix `cms_sc_`, 256 random bits as 64 hex digits, and a CRC-32 checksum as 8 hex digits. `ServiceCredentialToken::parse()` refuses a token whose checksum does not match before anything is looked up. A token is shown once, when it is issued, and stored only as its SHA-256, `hash()`, with an expiry, which every credential has.

What a store keeps is an `IssuedCredential`: the actor, the chain, the issuer kind, the ceiling, the actor's generation when it was issued, and the expiry. `IssuedCredential::principal($actor, $chain, $now)` decides a verification from the current state of those actors. Every verifier decides through it, so the fake and a store on a database cannot differ in the rules.

### Issuer kinds and classification ceilings

`IssuerKind` says what a credential was issued for: `Agent` for an agent, and `Service` for an integration, a sidecar, an addon or an IdP connection. The kind travels with the principal, so the kernel can refuse what an agent may not do (invariant 18). `ClassificationAccess` is the highest data classification a principal may read, ordered `Public`, `Internal`, `Confidential`, `Personal` and `Sensitive` (PRD 12.2). A credential's ceiling never exceeds `IssuerKind::maximumCeiling()`: `Confidential` for an agent, so personal data never reaches an agent (PRD 2.31), and `Sensitive` for a service. `ActorPrincipal`, `IssuedCredential` and the testkit's `ServiceCredentialSpec` throw `InvalidIdentity` for a higher ceiling, and the table's check refuses one too.

### The access context

`AccessContext` is what the pipelines consume: the principal, the access regions of its compiled grants and its classification access. An `AccessRegion` is a `NodePath`, an ltree path of the node tree, with the subtrees below it that it does not reach (PRD 5.10). The regions of a context are disjoint: a region lies inside another only within one of its exceptions, where a more specific allow sits below a deny. Its classification access never exceeds the principal's ceiling. The kernel computes it once per call from the verified principal; `AccessContext::anonymous()` is the context of a call without a credential, with no regions and public access.

## The default: PostgresCredentialVerifier

`cbox-cms.contracts` binds `CredentialVerifier` to `Cbox\Cms\Core\Identity\Adapter\PostgresCredentialVerifier`. It parses the token, then reads the credential by its hash together with its actor, and the actors of its chain in order, through the lookup functions `cms_identity_credential`, `cms_identity_delegations` and `cms_identity_actor` on the default connection as the app role, on the write PDO. The lookups run as the owner role and return only the rows of the one token asked for, because the identity tables give the app role no row without an actor context. The Clock gives the time. The core runs the shared suite against it in `packages/core/tests/Contract/PostgresCredentialVerifierContractTest.php`.

## The fake and the seeder

`Cbox\Cms\Testkit\Identity\FakeIdentity` is the fake; the [actor directory](actor-directory.md) page describes it and its seeder. `IdentitySeeder::issue(ServiceCredentialSpec $spec)` issues a service credential and returns it as a `TransportCredential`. The spec names the service actor, the issuer kind, the ceiling, the expiry and the chain. The seeder refuses an actor that is unknown or not active, an actor that is not of the class service, a chain actor that is not active, and an expiry that is not after the clock's time. `FakeIdentity::lookups()` counts the verifications that looked a credential up. This example verifies an agent's credential on the fake. It is in the `Unit` suite:

<!-- example: examples/Unit/Identity/ServiceCredentialTest.php -->
```php
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
```

## Running the shared suite against a replacement

Every implementation runs the testkit's shared suite, the trait `Cbox\Cms\Testkit\Identity\CredentialVerifierContract`, in a PHPUnit test class in its `tests/Contract` directory. Like `ActorDirectoryContract`, it has one abstract method, `identity(Clock $clock): IdentityHarness`, which returns a harness for a new, empty verifier whose parts read the time from `$clock`. The cases move that clock past a credential's expiry. They cover the anonymous principal, the principal of a service and an agent credential with its chain, and every refusal in its order: a malformed token or a wrong checksum, an unknown token, an expired one, an actor or a chain actor that is not active, and a generation below the actor's after a revocation or a reactivation.

The example decorates a verifier and counts its refusals by reason. The decorator passes every call through:

<!-- example-file: examples/Contract/Identity/RejectionCountingVerifier.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Identity;

use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\TransportCredential;

/**
 * A verifier that counts the refusals of the verifier it decorates by reason, for a metric per
 * reason (PRD 5.16: refusals without a known actor are counted, not logged). It passes every call
 * through and changes no result.
 */
final class RejectionCountingVerifier implements CredentialVerifier
{
    /** @var array<string, int> by reason */
    private array $refusals = [];

    public function __construct(private readonly CredentialVerifier $verifier) {}

    public function verify(?TransportCredential $credential): Principal
    {
        try {
            return $this->verifier->verify($credential);
        } catch (CredentialRejected $rejected) {
            $this->refusals[$rejected->reason->value] = $this->refusals($rejected->reason) + 1;

            throw $rejected;
        }
    }

    public function refusals(CredentialErrorCode $reason): int
    {
        return $this->refusals[$reason->value] ?? 0;
    }
}
```

Its harness wraps the harness of the verifier it decorates:

<!-- example-file: examples/Contract/Identity/RejectionCountingIdentity.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Identity;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;

/**
 * The harness the shared suites run RejectionCountingVerifier through. It wraps the harness of the
 * decorated verifier: the seeder and the directory are that harness's, and the verifier is the
 * decorator over its verifier.
 */
final readonly class RejectionCountingIdentity implements IdentityHarness
{
    private RejectionCountingVerifier $verifier;

    public function __construct(private IdentityHarness $identity)
    {
        $this->verifier = new RejectionCountingVerifier($identity->verifier());
    }

    public function directory(): ActorDirectory
    {
        return $this->identity->directory();
    }

    public function verifier(): RejectionCountingVerifier
    {
        return $this->verifier;
    }

    public function addActor(ActorClass $class, ActorState $state = ActorState::Active): Actor
    {
        return $this->identity->addActor($class, $state);
    }

    public function issue(ServiceCredentialSpec $spec): TransportCredential
    {
        return $this->identity->issue($spec);
    }

    public function changeState(ActorId $id, ActorState $state): Actor
    {
        return $this->identity->changeState($id, $state);
    }

    public function revokeCredentials(ActorId $id): Actor
    {
        return $this->identity->revokeCredentials($id);
    }
}
```

The test class runs both shared suites through that harness, and tests what the decorator adds. It is in the `Contract` suite:

<!-- example: examples/Contract/Identity/RejectionCountingVerifierContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Identity;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Testkit\Identity\ActorDirectoryContract;
use Cbox\Cms\Testkit\Identity\CredentialVerifierContract;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared identity suites against RejectionCountingVerifier, through the harness
 * RejectionCountingIdentity. In the application the decorated verifier is the default,
 * PostgresCredentialVerifier; here it is the testkit's FakeIdentity, so the suites run without
 * services. The class also tests what the decorator adds.
 */
final class RejectionCountingVerifierContractTest extends TestCase
{
    use ActorDirectoryContract;
    use CredentialVerifierContract;

    #[Override]
    protected function identity(Clock $clock): IdentityHarness
    {
        return new RejectionCountingIdentity(new FakeIdentity($clock));
    }

    #[Test]
    public function the_decorator_counts_every_refusal_by_its_reason(): void
    {
        $identity = new RejectionCountingIdentity(new FakeIdentity);
        $actor = $identity->addActor(ActorClass::Service);
        $credential = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, new DateTimeImmutable('2100-01-01T00:00:00Z')));
        $verifier = $identity->verifier();

        $verifier->verify($credential);
        $verifier->verify(null);

        foreach ([new TransportCredential('not a token'), new TransportCredential('')] as $malformed) {
            try {
                $verifier->verify($malformed);
            } catch (CredentialRejected) {
                // Counted by the decorator.
            }
        }

        self::assertSame(
            [2, 0],
            [$verifier->refusals(CredentialErrorCode::Malformed), $verifier->refusals(CredentialErrorCode::Unknown)],
        );
    }
}
```
