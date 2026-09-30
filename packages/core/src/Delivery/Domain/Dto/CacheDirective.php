<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * How long a shared cache, the edge, may keep an answer of the delivery API (PRD 8.10, 8.12):
 * either not at all (`private, no-store`), or for $maxAge seconds, and, when $staleWhileRevalidate
 * and $staleIfError are above zero, served stale while it is refetched or while the origin fails.
 * A shared answer's $maxAge is at least one second and never past the valid_until of what it
 * depends on (invariant 17); an answer whose next change is a removal has no stale seconds.
 */
#[Internal]
final readonly class CacheDirective
{
    private function __construct(
        public bool $shared,
        public int $maxAge,
        public int $staleWhileRevalidate,
        public int $staleIfError,
    ) {}

    public static function noStore(): self
    {
        return new self(false, 0, 0, 0);
    }

    /**
     * @throws InvalidArgumentException for a max age below one second or stale seconds below zero
     */
    public static function shared(int $maxAge, int $staleWhileRevalidate = 0, int $staleIfError = 0): self
    {
        if ($maxAge < 1 || $staleWhileRevalidate < 0 || $staleIfError < 0) {
            throw new InvalidArgumentException(sprintf('A shared answer is kept for at least a second and stale for no negative time, got %d, %d and %d.', $maxAge, $staleWhileRevalidate, $staleIfError));
        }

        return new self(true, $maxAge, $staleWhileRevalidate, $staleIfError);
    }

    /**
     * Whether a shared cache may serve the answer stale.
     */
    public function stale(): bool
    {
        return $this->staleWhileRevalidate > 0 || $this->staleIfError > 0;
    }
}
