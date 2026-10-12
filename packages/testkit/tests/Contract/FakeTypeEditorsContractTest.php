<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Testkit\Editors\FakeTypeEditors;
use Cbox\Cms\Testkit\Editors\SampleEditors;
use Cbox\Cms\Testkit\Editors\TypeEditorsContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared TypeEditors contract suite against the testkit's fake, with three sample types.
 */
final class FakeTypeEditorsContractTest extends TestCase
{
    use TypeEditorsContract;

    #[Override]
    protected function editors(): TypeEditors
    {
        return new FakeTypeEditors(...SampleEditors::many(3));
    }
}
