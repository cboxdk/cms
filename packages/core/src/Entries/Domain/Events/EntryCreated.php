<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Override;

/**
 * An entry was created, entry.created version 1 (PRD 5.4, 7.2): the entry at its first version,
 * with its type and home node. It carries ids only, never a field's value (invariant 10); a
 * subscriber reads the entry's state.
 */
#[Experimental]
final readonly class EntryCreated implements Event
{
    public const string NAME = 'entry.created';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'entry';

    public function __construct(
        private int $version,
        private EntryCreatedV1 $payload,
    ) {}

    #[Override]
    public static function type(): EventType
    {
        return new EventType(self::NAME, 1);
    }

    #[Override]
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType(self::AGGREGATE), $this->payload->entry, $this->version);
    }

    #[Override]
    public function payload(): EntryCreatedV1
    {
        return $this->payload;
    }
}
