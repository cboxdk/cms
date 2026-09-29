<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\FixtureSupport;

use Cbox\Cms\Contracts\Events\StoredEvent;

/**
 * The handler of a registry fixture's subscriber, which does nothing: the registry tests read only
 * the subscriber's declaration.
 */
trait IgnoresEvents
{
    public function handle(StoredEvent $event): void {}
}
