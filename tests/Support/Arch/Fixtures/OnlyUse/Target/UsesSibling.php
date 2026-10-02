<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Target;

use Cbox\Cms\Tests\Support\Arch\Fixtures\OnlyUse\Allowed\Permitted;

/**
 * A target that uses another target and an allowed class.
 */
final readonly class UsesSibling
{
    public function __construct(public Sibling $sibling) {}

    public function permitted(): Permitted
    {
        return $this->sibling->permitted;
    }
}
