<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * How many rows a page of a listing query holds (PRD 8.8): from 1 to MAX, DEFAULT when the query
 * leaves it out. A listing reads in keyset order after the id of the last row the caller has, and
 * its cost is the rows it may return, so a page stays inside an actor's budget.
 */
#[Experimental]
final readonly class ListLimit
{
    public const int DEFAULT = 50;

    public const int MAX = 100;

    /**
     * The limit, checked.
     *
     * @throws InvalidArgumentException when it is below 1 or above MAX
     */
    public static function checked(int $limit): int
    {
        if ($limit < 1 || $limit > self::MAX) {
            throw new InvalidArgumentException(sprintf('A page of a listing holds 1 to %d rows; %d was asked for.', self::MAX, $limit));
        }

        return $limit;
    }
}
