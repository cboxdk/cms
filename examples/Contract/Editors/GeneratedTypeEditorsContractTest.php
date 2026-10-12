<?php

declare(strict_types=1);

namespace Examples\Contract\Editors;

use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Testkit\Editors\TypeEditorsContract;
use Override;
use PHPUnit\Framework\TestCase;
use Workbench\App\Cms\Generated\GeneratedTypeEditors;

/**
 * The shared TypeEditors suite against the editor descriptors cms:generate writes. In an
 * application the class is App\Cms\Generated\GeneratedTypeEditors; here it is the workbench's,
 * generated from its fixture schema. The generated class has a constructor without arguments, so
 * the suite needs no application.
 */
final class GeneratedTypeEditorsContractTest extends TestCase
{
    use TypeEditorsContract;

    #[Override]
    protected function editors(): TypeEditors
    {
        return new GeneratedTypeEditors;
    }
}
