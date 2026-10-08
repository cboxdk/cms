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
 * A node was created, node.created version 1 (PRD 5.8, 7.13): the node at version 1, with its
 * parent and its kind.
 */
#[Experimental]
final readonly class NodeCreated implements Event
{
    public const string NAME = 'node.created';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'node';

    public function __construct(
        private int $version,
        private NodeCreatedV1 $payload,
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
    public function payload(): NodeCreatedV1
    {
        return $this->payload;
    }
}
