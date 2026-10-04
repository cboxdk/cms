<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contract;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Panel\Doctor\Domain\Checks\AddonBundlesCheck;
use Cbox\Cms\Panel\Doctor\Domain\Dto\BundleState;
use Cbox\Cms\Panel\Tests\Doctor\Fakes\FakeAddonBundlesProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against AddonBundlesCheck, with a fake probe: a bundle that
 * is what cms:build compiled against one with a changed file.
 */
final class AddonBundlesDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new AddonBundlesCheck(new FakeAddonBundlesProbe([new BundleState(new AddonNamespace('tally'))]));
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new AddonBundlesCheck(new FakeAddonBundlesProbe([new BundleState(new AddonNamespace('tally'), ['the file assets/addon.js has another SHA-384 than cms:build compiled'])]));
    }
}
