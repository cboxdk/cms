<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions\Fixtures;

use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventPayload;

/**
 * The payload of CounterReset, which carries nothing.
 */
final readonly class CounterResetV1 implements EventPayload
{
    public function data(): EventData
    {
        return EventData::empty();
    }
}
