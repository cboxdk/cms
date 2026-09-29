<?php

declare(strict_types=1);

namespace Examples\Postgres\Events;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;

/**
 * An addon's event: a warehouse's stock was counted. The class names the event, stock.counted
 * version 1; its payload is the versioned DTO StockCountedV1.
 */
final readonly class StockCounted implements Event
{
    public function __construct(
        private WarehouseId $warehouse,
        private int $version,
        private StockCountedV1 $payload,
    ) {}

    public static function type(): EventType
    {
        return new EventType('stock.counted', 1);
    }

    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('warehouse'), $this->warehouse, $this->version);
    }

    public function payload(): StockCountedV1
    {
        return $this->payload;
    }
}
