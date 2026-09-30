<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Fragments\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use DateInterval;
use InvalidArgumentException;

/**
 * The settings of the invalidation subscriber, from cbox-cms.fragments (PRD 8.12 point 1):
 *
 * - fenceSeconds: how long a purge fence lives, 1 to MAX_FENCE_SECONDS. While it lives, the
 *   fragment store refuses a fragment that depends on the purged key and was built at or below
 *   the purge's commit position, so it must outlast the slowest fragment build and the lag of a
 *   read replica.
 */
#[Internal]
final readonly class InvalidationSettings
{
    public const int DEFAULT_FENCE_SECONDS = 60;

    /** One day: a fragment build or a replica's lag is never that long. */
    public const int MAX_FENCE_SECONDS = 86_400;

    public function __construct(public int $fenceSeconds = self::DEFAULT_FENCE_SECONDS)
    {
        if ($fenceSeconds < 1 || $fenceSeconds > self::MAX_FENCE_SECONDS) {
            throw new InvalidArgumentException(sprintf('The setting cbox-cms.fragments.fence_seconds must be a whole number from 1 to %d; it is %d.', self::MAX_FENCE_SECONDS, $fenceSeconds));
        }
    }

    /**
     * How long a fence lives after the purge that writes it.
     */
    public function fence(): DateInterval
    {
        return new DateInterval(sprintf('PT%dS', $this->fenceSeconds));
    }
}
