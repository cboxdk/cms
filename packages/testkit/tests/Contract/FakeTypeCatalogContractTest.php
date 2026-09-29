<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Schema\TypeCatalogContract;
use Cbox\Cms\Testkit\Tests\Schema\SampleTypes;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared TypeCatalog contract suite against the in-memory fake, given two types that share a
 * handle, one extended and with a group.
 */
final class FakeTypeCatalogContractTest extends TestCase
{
    use TypeCatalogContract;

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return new FakeTypeCatalog(SampleTypes::note(), SampleTypes::appNote());
    }
}
