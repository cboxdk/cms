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
 * A node moved with its subtree under another parent, node.moved version 1 (PRD 5.8, 7.13): the
 * node at the version the move gave it, with the parent it left and the parent it went below. The
 * invalidation purges the n- key of the node and of every node below it, because a breadcrumb or
 * a tree below any of them changed.
 */
#[Experimental]
final readonly class NodeMoved implements Event
{
    public const string NAME = 'node.moved';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'node';

    public function __construct(
        private int $version,
        private NodeMovedV1 $payload,
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
    public function payload(): NodeMovedV1
    {
        return $this->payload;
    }
}
