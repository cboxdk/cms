<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Contract;

use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Testkit\Editors\TypeEditorsContract;
use Cbox\Cms\Tests\TestCase;
use LogicException;
use Override;

/**
 * The shared TypeEditors contract suite against the class cms:generate wrote from the workbench's
 * schema, as the panel gets it: from the container, where the generated service provider bound it.
 */
final class WorkbenchTypeEditorsContractTest extends TestCase
{
    use TypeEditorsContract;

    #[Override]
    protected function editors(): TypeEditors
    {
        return $this->app?->make(TypeEditors::class) ?? throw new LogicException('The application is not booted.');
    }
}
