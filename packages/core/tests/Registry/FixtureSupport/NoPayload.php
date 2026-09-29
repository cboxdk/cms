<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\FixtureSupport;

use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventPayload;

/**
 * The payload of the registry fixtures' events, which carries nothing: the registry tests read only
 * the events' classes and types.
 */
final readonly class NoPayload implements EventPayload
{
    public function data(): EventData
    {
        return EventData::empty();
    }
}
