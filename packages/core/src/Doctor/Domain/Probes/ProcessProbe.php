<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What this process holds and serves: whether a database connection is configured in it, and
 * whether it serves HTTP.
 */
#[Internal]
interface ProcessProbe
{
    /** Whether `database.connections.<name>` is configured in this process. */
    public function connectionConfigured(string $name): bool;

    /** Whether this process serves HTTP requests, as opposed to running in the console. */
    public function servesHttp(): bool;
}
