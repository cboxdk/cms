<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec\Fixtures;

/**
 * A string-backed enum for a probe contract of the codec emitter.
 */
enum Tone: string
{
    case Warm = 'warm';
    case Cold = 'cold';
}
