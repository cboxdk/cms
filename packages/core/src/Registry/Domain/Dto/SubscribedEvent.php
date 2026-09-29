<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * An event a subscriber receives: the event class and the type it gives, the name and payload
 * version the log stores (PRD 7.2), so the registry answers by class for a changeset's events and by
 * type for an event read from the log.
 */
#[Experimental]
final readonly class SubscribedEvent
{
    public string $class;

    public function __construct(string $class, public EventType $type)
    {
        $this->class = InvalidRegistryEntry::checkClass('event class', $class);
    }
}
