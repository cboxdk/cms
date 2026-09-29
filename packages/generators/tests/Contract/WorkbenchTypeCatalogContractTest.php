<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Contract;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Testkit\Schema\TypeCatalogContract;
use Cbox\Cms\Tests\TestCase;
use LogicException;
use Override;

/**
 * The shared TypeCatalog contract suite against the catalog cms:generate wrote from the
 * workbench's schema, as the kernel gets it: from the container, where the generated service
 * provider bound it.
 */
final class WorkbenchTypeCatalogContractTest extends TestCase
{
    use TypeCatalogContract;

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return $this->app?->make(TypeCatalog::class) ?? throw new LogicException('The application is not booted.');
    }
}
