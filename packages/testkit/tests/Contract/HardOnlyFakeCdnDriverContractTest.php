<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Cdn\CdnDriverContract;
use Cbox\Cms\Testkit\Cdn\CdnDriverHarness;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared CdnDriver contract suite against a fake that always purges hard, as Cloudflare does (PRD 8.12 point 3), with the default keys per request.
 */
final class HardOnlyFakeCdnDriverContractTest extends TestCase
{
    use CdnDriverContract;

    #[Override]
    protected function cdn(): CdnDriverHarness
    {
        return new FakeCdnDriver(softPurge: false);
    }
}
