<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Override;

/**
 * A mount was created, mount.created version 1 (PRD 5.8, 7.13): the mount node at version 1, with
 * its parent and the source whose placements it shows.
 */
#[Experimental]
final readonly class MountCreated implements Event
{
    public const string NAME = 'mount.created';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'node';

    public function __construct(
        private int $version,
        private MountCreatedV1 $payload,
    ) {}

    #[Override]
    public static function type(): EventType
    {
        return new EventType(self::NAME, 1);
    }

    #[Override]
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType(self::AGGREGATE), $this->payload->node, $this->version);
    }

    #[Override]
    public function payload(): MountCreatedV1
    {
        return $this->payload;
    }
}
