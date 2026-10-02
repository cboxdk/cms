<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\Argon2idCheck;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakePasswordHashingProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against Argon2idCheck, with a fake probe: a PHP with
 * Argon2id against one without.
 */
final class Argon2idDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new Argon2idCheck(new FakePasswordHashingProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new Argon2idCheck(new FakePasswordHashingProbe(argon2id: false));
    }
}
