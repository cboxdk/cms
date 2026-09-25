<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Internal;
use Closure;

/**
 * Something that happens while a contested claim in the FakeIdempotencyStore waits, once it has
 * waited $afterMilliseconds.
 */
#[Internal]
final readonly class FakeWaitEvent
{
    /**
     * @param  Closure(): void  $event
     */
    public function __construct(
        public int $afterMilliseconds,
        public Closure $event,
    ) {}
}
