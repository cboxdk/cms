<?php

declare(strict_types=1);

namespace Examples\Contract\TypeTables;

use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\TypeTables\FakeTypeTableReader;
use Cbox\Cms\Testkit\TypeTables\TypeTableReaderContract;
use Cbox\Cms\Testkit\TypeTables\TypeTableSeed;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared TypeTableReader suite against a reader. typeTableReader() gets the suite's type and
 * rows: a replacement for the kernel's reader writes the rows to its own store here and returns
 * itself over a catalog with the type. This example runs it against the testkit's in-memory
 * reader, which keeps the rows it is given.
 */
final class InMemoryTypeTableReaderContractTest extends TestCase
{
    use TypeTableReaderContract;

    #[Override]
    protected function typeTableReader(TypeDefinition $type, TypeTableSeed ...$rows): TypeTableReader
    {
        return new FakeTypeTableReader(new FakeTypeCatalog($type))->with($type, ...$rows);
    }
}
