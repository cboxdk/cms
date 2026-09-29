<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a field of event data holds (PRD 7.2): ids, hashes of text and values that are not text.
 * There is no kind for a string, so text cannot enter an event's data (PRD 6.5 invariant 10).
 */
#[Experimental]
enum DatumKind: string
{
    /** An id, as an EventIdentifier. */
    case Identifier = 'identifier';

    /** The hash of a text, as a TextHash. */
    case Hash = 'hash';

    case Integer = 'integer';

    case Boolean = 'boolean';

    /** An instant, in UTC with microseconds. */
    case Time = 'time';

    /** The value of a backed enum case. */
    case Enum = 'enum';

    case Null = 'null';

    /** A list of data of any kind. */
    case List = 'list';
}
