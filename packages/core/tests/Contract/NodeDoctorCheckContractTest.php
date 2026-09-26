<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\NodeCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeToolProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against NodeCheck, with fake probes: node on PATH against no node.
 */
final class NodeDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new NodeCheck(new FakeToolProbe, '22.13.0');
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new NodeCheck(new FakeToolProbe(node: null), '22.13.0');
    }
}
