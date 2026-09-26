<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeDoctorChecks;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * DoctorChecksBehaviour against the fake the doctor's action tests use.
 */
final class FakeDoctorChecksBehaviourTest extends TestCase
{
    use DoctorChecksBehaviour;

    #[Override]
    protected function doctorChecks(array $runtime, array $dev): DoctorChecks
    {
        return new FakeDoctorChecks($runtime, $dev);
    }
}
