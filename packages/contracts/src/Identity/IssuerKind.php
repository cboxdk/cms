<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;

/**
 * What a credential was issued for (PRD 5.16, 6.1). A session of a person who logged in has the
 * kind Human. A service credential issued for an agent has the kind Agent; one issued for an
 * integration, a sidecar, an addon or an IdP connection has the kind Service. The kind travels with
 * the principal, so the kernel can refuse what an agent may not do, such as change public
 * visibility (invariant 18), cap what an agent may read (PRD 2.31), and record who issued a command
 * (envelopeIssuer()).
 */
#[Experimental]
enum IssuerKind: string
{
    case Human = 'human';
    case Agent = 'agent';
    case Service = 'service';

    /**
     * The highest classification ceiling a credential of this kind may have. An agent's never
     * exceeds confidential, so personal data never reaches an agent (PRD 2.31, 12.2, 22). A
     * person's session and a service may reach sensitive; the grants of the actor's roles decide
     * what they read.
     */
    public function maximumCeiling(): ClassificationAccess
    {
        return match ($this) {
            self::Agent => ClassificationAccess::Confidential,
            self::Human, self::Service => ClassificationAccess::Sensitive,
        };
    }

    /**
     * The issuer kind a changeset records for a command made with a credential of this kind (PRD
     * 5.5): a person's session as human, an agent's credential as agent, and a service's as system.
     */
    public function envelopeIssuer(): EnvelopeIssuer
    {
        return match ($this) {
            self::Human => EnvelopeIssuer::Human,
            self::Agent => EnvelopeIssuer::Agent,
            self::Service => EnvelopeIssuer::System,
        };
    }

    public function permits(ClassificationAccess $ceiling): bool
    {
        return $this->maximumCeiling()->allows($ceiling);
    }
}
