<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Doctor\Domain\Checks\BrandingCheck;
use Cbox\Cms\Panel\Tests\Doctor\Fakes\FakeBrandingProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against BrandingCheck, with a fake probe: a brand that can
 * be used against one whose logo has no alternative text.
 */
final class BrandingDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new BrandingCheck(new FakeBrandingProbe(new Branding('Skovbo Content')));
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new BrandingCheck(new FakeBrandingProbe(reasons: ['cbox-cms.panel.branding.logo.alt must be the alternative text a screen reader announces for the image, 1 to 150 characters; it is missing.']));
    }
}
