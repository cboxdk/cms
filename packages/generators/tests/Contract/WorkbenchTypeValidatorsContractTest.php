<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Contract;

use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Testkit\Validation\TypeValidatorsContract;
use Cbox\Cms\Tests\TestCase;
use LogicException;
use Override;

/**
 * The shared TypeValidators contract suite against the validators cms:generate wrote from the
 * workbench's schema, as the kernel gets them: from the container, where the generated service
 * provider bound them next to the catalog.
 */
final class WorkbenchTypeValidatorsContractTest extends TestCase
{
    use TypeValidatorsContract;

    #[Override]
    protected function validators(): TypeValidators
    {
        return $this->app?->make(TypeValidators::class) ?? throw new LogicException('The application is not booted.');
    }

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return $this->app?->make(TypeCatalog::class) ?? throw new LogicException('The application is not booted.');
    }
}
