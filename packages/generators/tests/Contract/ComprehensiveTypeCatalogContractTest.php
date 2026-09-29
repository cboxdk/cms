<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Contract;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\GeneratedTypeCatalog;
use Cbox\Cms\Testkit\Schema\TypeCatalogContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared TypeCatalog contract suite against the committed golden catalog of the comprehensive
 * example (PRD 11.12): every core field type, a group once and repeated, a confidential field and
 * the app's extension.
 */
final class ComprehensiveTypeCatalogContractTest extends TestCase
{
    use TypeCatalogContract;

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return new GeneratedTypeCatalog;
    }
}
