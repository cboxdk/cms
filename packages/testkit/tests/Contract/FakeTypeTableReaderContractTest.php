<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\TypeTables\FakeTypeTableReader;
use Cbox\Cms\Testkit\TypeTables\TypeTableReaderContract;
use Cbox\Cms\Testkit\TypeTables\TypeTableSeed;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared TypeTableReader contract suite against the in-memory fake, over a FakeTypeCatalog
 * with the suite's type.
 */
final class FakeTypeTableReaderContractTest extends TestCase
{
    use TypeTableReaderContract;

    #[Override]
    protected function typeTableReader(TypeDefinition $type, TypeTableSeed ...$rows): TypeTableReader
    {
        return new FakeTypeTableReader(new FakeTypeCatalog($type))->with($type, ...$rows);
    }
}
