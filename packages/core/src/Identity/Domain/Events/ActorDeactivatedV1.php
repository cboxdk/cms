<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Ids\ActorId;
use Override;

/**
 * Version 1 of the payload of actor.deactivated: the actor, what deactivated it, the credential
 * generation it now has, below which every credential is refused, and how many direct grants the
 * deactivation ended.
 */
#[Experimental]
final readonly class ActorDeactivatedV1 implements EventPayload
{
    public function __construct(
        public ActorId $actor,
        public DeactivationSource $source,
        public int $credentialGeneration,
        public int $grantsEnded,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('actor', EventDatum::identifier($this->actor))
            ->with('source', EventDatum::enum($this->source))
            ->with('credential_generation', EventDatum::integer($this->credentialGeneration))
            ->with('grants_ended', EventDatum::integer($this->grantsEnded));
    }
}
