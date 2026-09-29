<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs\Fixtures;

/**
 * A string-backed enum for the codec tests.
 */
enum Shade: string
{
    case Light = 'light';
    case Dark = 'dark';
}
