<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan\EventPayloads;

/**
 * A backed enum for the fixture EventPayloads.php.inc, autoloaded so the rule can read it by name.
 */
enum CounterState: string
{
    case Open = 'open';
}
