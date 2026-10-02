<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol\Fixtures;

/**
 * A locker whose code is a plain string, so it cannot hold Omitted for a reader below the code's
 * classification, which the reader refuses.
 */
final readonly class PlainLocker
{
    public function __construct(
        public string $name,
        public string $code,
    ) {}
}
