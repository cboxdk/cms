<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Contract;

use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated\GeneratedTypeEditors;
use Cbox\Cms\Testkit\Editors\TypeEditorsContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared TypeEditors contract suite against the golden class of the comprehensive example,
 * shop:product extended by app, with every core field type, a group once and repeated, every
 * classification and both agents flags.
 */
final class ComprehensiveTypeEditorsContractTest extends TestCase
{
    use TypeEditorsContract;

    #[Override]
    protected function editors(): TypeEditors
    {
        return new GeneratedTypeEditors;
    }
}
