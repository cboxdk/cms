<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs\Fixtures;

/**
 * An int-backed enum for the codec tests.
 */
enum Rank: int
{
    case First = 1;
    case Second = 2;
}
