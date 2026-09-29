<?php

declare(strict_types=1);

namespace Examples\Contract\Validation;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Testkit\Validation\TypeValidatorsContract;
use Override;
use PHPUnit\Framework\TestCase;
use Workbench\App\Cms\Generated\GeneratedTypeCatalog;
use Workbench\App\Cms\Generated\GeneratedTypeValidators;

/**
 * The shared TypeValidators suite against the validators cms:generate writes, next to the catalog
 * of the same schema. In an application the classes are in App\Cms\Generated; here they are the
 * workbench's. Both have a constructor without arguments, so the suite needs no application.
 */
final class GeneratedTypeValidatorsContractTest extends TestCase
{
    use TypeValidatorsContract;

    #[Override]
    protected function validators(): TypeValidators
    {
        return new GeneratedTypeValidators;
    }

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return new GeneratedTypeCatalog;
    }
}
