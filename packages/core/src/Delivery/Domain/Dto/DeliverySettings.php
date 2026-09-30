<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The settings of the delivery API (PRD 8.10, 8.12), `cbox-cms.delivery`: how many seconds a
 * fragment and a shared cache keep an answer at most, never past its valid_until, and how many
 * seconds the edge may serve a changed answer stale while it refetches it and while the origin
 * fails. Stale seconds apply only to an answer whose next change is not a removal.
 */
#[Internal]
final readonly class DeliverySettings
{
    /** The longest a fragment and the edge keep an answer, a day. */
    public const int MAX_AGE_LIMIT = 86400;

    /** The longest the edge may serve an answer stale when the origin fails, an hour (PRD 8.12 point 4). */
    public const int STALE_IF_ERROR_LIMIT = 3600;

    /**
     * @throws InvalidArgumentException for a value outside its range
     */
    public function __construct(
        public int $maxAge = 300,
        public int $staleWhileRevalidate = 30,
        public int $staleIfError = self::STALE_IF_ERROR_LIMIT,
    ) {
        if ($maxAge < 1 || $maxAge > self::MAX_AGE_LIMIT) {
            throw new InvalidArgumentException(sprintf('The delivery API keeps an answer 1 to %d seconds, got %d.', self::MAX_AGE_LIMIT, $maxAge));
        }

        if ($staleWhileRevalidate < 0 || $staleWhileRevalidate > self::MAX_AGE_LIMIT) {
            throw new InvalidArgumentException(sprintf('An answer is served stale while it is refetched 0 to %d seconds, got %d.', self::MAX_AGE_LIMIT, $staleWhileRevalidate));
        }

        if ($staleIfError < 0 || $staleIfError > self::STALE_IF_ERROR_LIMIT) {
            throw new InvalidArgumentException(sprintf('An answer is served stale while the origin fails 0 to %d seconds, got %d.', self::STALE_IF_ERROR_LIMIT, $staleIfError));
        }
    }
}
