<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\TypeTables\ColumnFilter;
use Cbox\Cms\Contracts\TypeTables\ColumnOrder;

/**
 * One case of the shared suite TypeTableReaderContract: its name, the filters and the order of the
 * query, and the rows the reader must give for them, by the suite's names e01 to e10.
 */
#[Experimental]
final readonly class ReaderSuiteCase
{
    /**
     * @param  list<ColumnFilter>  $filters
     * @param  list<ColumnOrder>  $order
     * @param  list<string>  $expected
     */
    public function __construct(
        public string $name,
        public array $filters,
        public array $order,
        public array $expected,
    ) {}
}
