<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Events\Fixtures;

enum CounterState: string
{
    case Open = 'open';
    case Closed = 'closed';
}
