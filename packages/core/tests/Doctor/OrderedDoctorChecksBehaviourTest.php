<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\OrderedDoctorChecks;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * DoctorChecksBehaviour against OrderedDoctorChecks, the list CoreServiceProvider builds.
 */
final class OrderedDoctorChecksBehaviourTest extends TestCase
{
    use DoctorChecksBehaviour;

    #[Override]
    protected function doctorChecks(array $runtime, array $dev): DoctorChecks
    {
        return new OrderedDoctorChecks($runtime, $dev);
    }
}
