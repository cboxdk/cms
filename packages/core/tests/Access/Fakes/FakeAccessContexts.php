<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Override;

/**
 * AccessContexts without a database: the anonymous context for the anonymous principal, and for an
 * actor the classification access and regions a test granted it with grant(), capped by the
 * credential's ceiling, or public access and no regions when it was granted nothing, as an actor
 * without grants has. It records every principal it was asked for.
 */
final class FakeAccessContexts implements AccessContexts
{
    /** @var list<Principal> */
    public array $asked = [];

    /** @var array<string, array{ClassificationAccess, list<AccessRegion>}> by actor id */
    private array $grants = [];

    public function grant(ActorId $actor, ClassificationAccess $access, AccessRegion ...$regions): self
    {
        $this->grants[$actor->toString()] = [$access, array_values($regions)];

        return $this;
    }

    #[Override]
    public function for(Principal $principal): AccessContext
    {
        $this->asked[] = $principal;

        if (! $principal instanceof ActorPrincipal) {
            return AccessContext::anonymous();
        }

        [$access, $regions] = $this->grants[$principal->actor->toString()] ?? [ClassificationAccess::Public, []];

        return new AccessContext($principal, $regions, $access->atMost($principal->classificationCeiling()));
    }
}
