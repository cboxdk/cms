<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Access\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Closure;
use Illuminate\Http\Request;

/**
 * Where a list page starts (PRD 5.10, 13.4): the access lists are read a keyset page at a time,
 * after the id of the last row the reader has, and a page carries that id in its address as the
 * query parameter PARAMETER, `?after=<id>`, so the address is the state and a page can be opened
 * again. A value that is not an id of the list's kind, such as one typed by hand, starts the list
 * from its first row, as a missing one does.
 */
#[Internal]
final readonly class ListCursor
{
    public const string PARAMETER = 'after';

    private function __construct() {}

    /**
     * The id the page starts after, parsed by $parse, or null to start from the first row.
     *
     * @template TId of object
     *
     * @param  Closure(string): TId  $parse  the id's parser, such as GrantId::fromString(...)
     * @return TId|null
     */
    public static function of(Request $request, Closure $parse): ?object
    {
        $after = $request->query(self::PARAMETER);

        if (! is_string($after) || $after === '') {
            return null;
        }

        try {
            return $parse($after);
        } catch (InvalidUuid7) {
            return null;
        }
    }
}
