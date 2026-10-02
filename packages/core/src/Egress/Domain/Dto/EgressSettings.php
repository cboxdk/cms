<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Egress\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The timeouts of the egress gateway, from `cbox-cms.egress`: how long it waits for a connection,
 * and how long a request may take in all, in milliseconds. The defaults are PRD 7.14's for a
 * webhook delivery: 2 seconds to connect, 10 in all.
 */
#[Internal]
final readonly class EgressSettings
{
    /** The longest connect timeout. */
    public const int MAX_CONNECT_MILLISECONDS = 30_000;

    /** The longest total timeout: no request may hold a worker longer. */
    public const int MAX_TIMEOUT_MILLISECONDS = 60_000;

    /**
     * @throws InvalidArgumentException when a timeout is out of range or the connect timeout is above the total
     */
    public function __construct(public int $connectTimeoutMilliseconds, public int $timeoutMilliseconds)
    {
        if ($connectTimeoutMilliseconds < 1 || $connectTimeoutMilliseconds > self::MAX_CONNECT_MILLISECONDS) {
            throw new InvalidArgumentException(sprintf('The connect timeout is 1 to %d ms, got %d.', self::MAX_CONNECT_MILLISECONDS, $connectTimeoutMilliseconds));
        }

        if ($timeoutMilliseconds < $connectTimeoutMilliseconds || $timeoutMilliseconds > self::MAX_TIMEOUT_MILLISECONDS) {
            throw new InvalidArgumentException(sprintf('The total timeout is from the connect timeout, %d ms, to %d ms, got %d.', $connectTimeoutMilliseconds, self::MAX_TIMEOUT_MILLISECONDS, $timeoutMilliseconds));
        }
    }
}
