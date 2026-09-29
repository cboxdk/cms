<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Cdn\CdnDriverContract;
use Cbox\Cms\Testkit\Cdn\CdnDriverHarness;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared CdnDriver contract suite against a fake that purges softly and takes three keys per request, so the split into requests is exercised.
 */
final class FakeCdnDriverContractTest extends TestCase
{
    use CdnDriverContract;

    #[Override]
    protected function cdn(): CdnDriverHarness
    {
        return new FakeCdnDriver(softPurge: true, maxKeysPerRequest: 3);
    }
}
