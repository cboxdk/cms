<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a credential was issued for (PRD 5.16, 6.1). A service credential issued for an agent has
 * the kind Agent; one issued for an integration, a sidecar, an addon or an IdP connection has the
 * kind Service. The kind travels with the principal, so the kernel can refuse what an agent may not
 * do, such as change public visibility (invariant 18), and cap what an agent may read (PRD 2.31).
 */
#[Experimental]
enum IssuerKind: string
{
    case Agent = 'agent';
    case Service = 'service';

    /**
     * The highest classification ceiling a credential of this kind may have. An agent's never
     * exceeds confidential, so personal data never reaches an agent (PRD 2.31, 12.2, 22).
     */
    public function maximumCeiling(): ClassificationAccess
    {
        return match ($this) {
            self::Agent => ClassificationAccess::Confidential,
            self::Service => ClassificationAccess::Sensitive,
        };
    }

    public function permits(ClassificationAccess $ceiling): bool
    {
        return $this->maximumCeiling()->allows($ceiling);
    }
}
