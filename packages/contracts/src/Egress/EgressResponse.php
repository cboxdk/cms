<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * The answer to an outbound request that was not a redirect: its status, 100 to 599 but not 3xx,
 * and its body.
 */
#[Experimental]
final readonly class EgressResponse
{
    /**
     * @throws InvalidArgumentException when the status is not one the gateway hands on
     */
    public function __construct(public int $status, public string $body)
    {
        if ($status < 100 || $status > 599 || ($status >= 300 && $status < 400)) {
            throw new InvalidArgumentException(sprintf('An outbound response the gateway hands on has a status from 100 to 599 that is not a redirect, got %d.', $status));
        }
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Ok for a 2xx status, Status for any other. */
    public function outcome(): EgressOutcome
    {
        return $this->successful() ? EgressOutcome::Ok : EgressOutcome::Status;
    }
}
