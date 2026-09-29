<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\EventType;
use Override;

/**
 * The test-only event of tally.add: a tally is at a new version, tally.raised version 1.
 */
final readonly class TallyRaised implements Event, EventPayload
{
    public function __construct(
        private TallyId $tally,
        private int $version,
    ) {}

    #[Override]
    public static function type(): EventType
    {
        return new EventType('tally.raised', 1);
    }

    #[Override]
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType(TallyTable::KIND), $this->tally, $this->version);
    }

    #[Override]
    public function payload(): EventPayload
    {
        return $this;
    }

    #[Override]
    public function data(): EventData
    {
        return EventData::empty();
    }
}
