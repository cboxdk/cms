<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Events\Fixtures;

use Cbox\Cms\Contracts\Ids\Identifier;

/**
 * The id of a test-only aggregate, a counter.
 */
final readonly class CounterId implements Identifier
{
    public function __construct(public string $value) {}

    public function toString(): string
    {
        return $this->value;
    }
}
