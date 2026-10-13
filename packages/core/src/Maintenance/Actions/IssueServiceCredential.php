<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Core\Maintenance\Domain\CredentialIssueRefused;
use Cbox\Cms\Core\Maintenance\Domain\Dto\CredentialIssue;
use Cbox\Cms\Core\Maintenance\Domain\Dto\IssuedToken;
use Cbox\Cms\Core\Maintenance\Domain\ServiceCredentialIssuer;

/**
 * Issues a service credential to a service actor (PRD 5.16), the action behind
 * cms:credential:issue, in the maintenance process. The credential has the issuer kind service, so
 * a write with it has the envelope's issuer kind system and may make content public, which an
 * agent's may not (invariant 18); it carries the actor's current credential generation, so
 * actor.deactivate and actor.credentials_revoke refuse it with everything else the actor holds; and
 * it expires when the issue says.
 *
 * It reads the actor through the ActorDirectory and refuses, issuing nothing, an actor that does
 * not exist or is not a service actor (validation_failed at actor), one that is not active
 * (actor_not_active), and an expiry at or before the Clock's now (validation_failed at expires).
 * The token comes from the ServiceCredentialIssuer and is shown once; the store keeps its hash.
 * The ceiling is held to what a service credential permits by IssuedCredential itself.
 */
#[Internal]
final readonly class IssueServiceCredential
{
    public function __construct(
        private ActorDirectory $actors,
        private ServiceCredentialIssuer $issuer,
        private Clock $clock,
    ) {}

    /**
     * @throws CredentialIssueRefused
     */
    public function issue(CredentialIssue $issue): IssuedToken
    {
        $actor = $this->actors->find($issue->actor);

        if (! $actor instanceof Actor || $actor->class !== ActorClass::Service) {
            throw CredentialIssueRefused::notServiceActor($issue->actor, $actor);
        }

        if (! $actor->isActive()) {
            throw CredentialIssueRefused::notActive($actor);
        }

        if ($issue->expiresAt <= $this->clock->now()) {
            throw CredentialIssueRefused::expiry();
        }

        $credential = new IssuedCredential($actor->id, [], IssuerKind::Service, $issue->ceiling, $actor->credentialGeneration, $issue->expiresAt);

        return new IssuedToken($this->issuer->issue($credential), $credential);
    }
}
