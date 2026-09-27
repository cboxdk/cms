<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Closure;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The contract suite with the checks under test injected.
 */
final class InjectedDoctorCheckContract extends TestCase
{
    use DoctorCheckContract;

    /** @var (Closure(bool): DoctorCheck)|null */
    public ?Closure $checks = null;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return ($this->checks ?? throw new LogicException('No checks were injected.'))(false);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return ($this->checks ?? throw new LogicException('No checks were injected.'))(true);
    }
}
