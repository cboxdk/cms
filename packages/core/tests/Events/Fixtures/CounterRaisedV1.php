<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Events\Fixtures;

use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\TextHash;
use DateTimeImmutable;

/**
 * Version 1 of the payload of the test-only event counter.raised.
 */
final readonly class CounterRaisedV1 implements EventPayload
{
    /**
     * @param  list<CounterId>  $linked
     */
    public function __construct(
        public int $before,
        public int $after,
        public TextHash $label,
        public CounterState $state,
        public DateTimeImmutable $at,
        public ?CounterId $parent,
        public bool $manual,
        public array $linked,
    ) {}

    public function data(): EventData
    {
        return EventData::empty()
            ->with('before', EventDatum::integer($this->before))
            ->with('after', EventDatum::integer($this->after))
            ->with('label', EventDatum::hash($this->label))
            ->with('state', EventDatum::enum($this->state))
            ->with('at', EventDatum::time($this->at))
            ->with('parent', $this->parent instanceof CounterId ? EventDatum::identifier($this->parent) : EventDatum::null())
            ->with('manual', EventDatum::boolean($this->manual))
            ->with('linked', EventDatum::list(...array_map(EventDatum::identifier(...), $this->linked)));
    }
}
