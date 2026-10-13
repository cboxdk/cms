<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\TypeTables\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Closure;

/**
 * One key of a type table page's order as a reader sorts and pages by it: the expression it sorts
 * by, its direction, how a cursor's value of it is bound, and whether it never holds null.
 */
#[Internal]
final readonly class SortKey
{
    /**
     * @param  Closure(FieldValue): (bool|int|string)  $bind
     */
    public function __construct(
        public string $expression,
        public SortDirection $direction,
        public Closure $bind,
        public bool $notNull,
    ) {}
}
