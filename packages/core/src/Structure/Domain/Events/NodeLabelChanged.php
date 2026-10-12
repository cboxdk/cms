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
 * A node got its label in one language, node.label_changed version 1 (PRD 5.8): the node at the
 * version the change gave it, and the language. The invalidation purges the node's n- key, because
 * a breadcrumb or a tree that shows the node changed.
 */
#[Experimental]
final readonly class NodeLabelChanged implements Event
{
    public const string NAME = 'node.label_changed';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'node';

    public function __construct(
        private int $version,
        private NodeLabelChangedV1 $payload,
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
    public function payload(): NodeLabelChangedV1
    {
        return $this->payload;
    }
}
