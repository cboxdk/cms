<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;

/**
 * Valkey on the application's Redis connection, with a short connect timeout.
 */
#[Internal]
interface ValkeyProbe
{
    /** Where the connection goes, without the password, such as "default (127.0.0.1:6379)". */
    public function target(): string;

    /**
     * Connects and sends PING.
     *
     * @throws ProbeFailed unavailable when the server cannot be reached, violation when it refuses the login
     */
    public function ping(): void;
}
