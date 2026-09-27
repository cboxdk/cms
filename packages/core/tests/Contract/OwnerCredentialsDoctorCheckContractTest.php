<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\OwnerCredentialsCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeProcessProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against OwnerCredentialsCheck, with a fake probe: a process without the owner connection against one that has it and is not the maintenance process.
 */
final class OwnerCredentialsDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new OwnerCredentialsCheck(new FakeProcessProbe, 'pgsql_owner', false);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new OwnerCredentialsCheck(new FakeProcessProbe(['pgsql_owner']), 'pgsql_owner', false);
    }
}
