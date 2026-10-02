<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Target;

use Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Allowed\Permitted;

/**
 * A target that uses an allowed class.
 */
final readonly class Sibling
{
    public function __construct(public Permitted $permitted) {}
}
