<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Target;

use Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Outside\Stranger;

/**
 * A target that uses a class outside what it may use.
 */
final readonly class UsesStranger
{
    public function stranger(): Stranger
    {
        return new Stranger;
    }
}
