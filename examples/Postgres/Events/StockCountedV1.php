<?php

declare(strict_types=1);

namespace Examples\Postgres\Events;

use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\TextHash;
use DateTimeImmutable;

/**
 * Version 1 of the payload of stock.counted. The counter's note is text, so the payload carries
 * only its hash; a string property here would fail the analysis (cboxCms.eventPayloadText).
 */
final readonly class StockCountedV1 implements EventPayload
{
    public function __construct(
        public int $before,
        public int $after,
        public StockLevel $level,
        public DateTimeImmutable $countedAt,
        public TextHash $noteHash,
    ) {}

    public function data(): EventData
    {
        return EventData::empty()
            ->with('before', EventDatum::integer($this->before))
            ->with('after', EventDatum::integer($this->after))
            ->with('level', EventDatum::enum($this->level))
            ->with('counted_at', EventDatum::time($this->countedAt))
            ->with('note_hash', EventDatum::hash($this->noteHash));
    }
}
