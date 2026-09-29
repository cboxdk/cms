<?php

declare(strict_types=1);

namespace Examples\Contract\Schema;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Testkit\Schema\TypeCatalogContract;
use Override;
use PHPUnit\Framework\TestCase;
use Workbench\App\Cms\Generated\GeneratedTypeCatalog;

/**
 * The shared TypeCatalog suite against the catalog cms:generate writes. In an application the
 * class is App\Cms\Generated\GeneratedTypeCatalog; here it is the workbench's, generated from its
 * fixture schema. The generated catalog has a constructor without arguments, so the suite needs no
 * application.
 */
final class GeneratedTypeCatalogContractTest extends TestCase
{
    use TypeCatalogContract;

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return new GeneratedTypeCatalog;
    }
}
