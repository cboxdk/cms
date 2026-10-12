<?php

declare(strict_types=1);

namespace Examples\Contract\Content;

use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Testkit\Content\FakeVariantHeadReader;
use Cbox\Cms\Testkit\Content\VariantHeadReaderContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared VariantHeadReader suite against a reader. variantHeadReader() gets the suite's heads,
 * each of the suite's type homed on HOME: a replacement for the kernel's reader writes them to its
 * own store here and returns itself, under a context whose regions reach HOME. This example runs
 * it against the testkit's in-memory reader, which keeps the heads it is given.
 */
final class InMemoryVariantHeadReaderContractTest extends TestCase
{
    use VariantHeadReaderContract;

    #[Override]
    protected function variantHeadReader(VariantHead ...$heads): VariantHeadReader
    {
        return new FakeVariantHeadReader()->with(...$heads);
    }
}
