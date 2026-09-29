<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec\Fixtures;

/**
 * An int-backed enum for a probe contract of the codec emitter.
 */
enum Step: int
{
    case One = 1;
    case Two = 2;
}
