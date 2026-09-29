<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Fields;

/**
 * The options of a select field in the tests of FieldReader and FieldWriter.
 */
enum Suit: string
{
    case Hearts = 'hearts';
    case Spades = 'spades';
}
