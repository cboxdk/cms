<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Testkit\Content\FakeVariantHeadReader;
use Cbox\Cms\Testkit\Content\VariantHeadReaderContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared VariantHeadReader contract suite against the in-memory fake.
 */
final class FakeVariantHeadReaderContractTest extends TestCase
{
    use VariantHeadReaderContract;

    #[Override]
    protected function variantHeadReader(VariantHead ...$heads): VariantHeadReader
    {
        return new FakeVariantHeadReader()->with(...$heads);
    }
}
