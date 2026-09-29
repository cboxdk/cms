<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol\Fixtures;

/**
 * An object of the probe schema whose every key has a default, so `{}` is a box.
 */
final readonly class Box
{
    public function __construct(public int $size = 1) {}
}
