<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Ids\GrantId;
use Override;

/**
 * A grant was given or ended, or its role's permissions changed, grant.changed version 1 (PRD
 * 5.10, 7.2): the grant at the version the change left it at, with the actor, the role and the
 * node; a change of a role's permissions gives one for every grant of the role that has not
 * ended. Whatever caches an actor's compiled access, or the credentials that act for it, drops it
 * on this event. It carries ids alone, never text (invariant 10).
 */
#[Experimental]
final readonly class GrantChanged implements Event
{
    public const string NAME = 'grant.changed';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'grant';

    public function __construct(
        private GrantId $grant,
        private int $version,
        private GrantChangedV1 $payload,
    ) {}

    #[Override]
    public static function type(): EventType
    {
        return new EventType(self::NAME, 1);
    }

    #[Override]
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType(self::AGGREGATE), $this->grant, $this->version);
    }

    #[Override]
    public function payload(): GrantChangedV1
    {
        return $this->payload;
    }
}
