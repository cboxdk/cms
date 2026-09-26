<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\LcMessagesCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeLcMessagesProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against LcMessagesCheck, with a fake probe: lc_messages C for both roles and the process against de_DE.UTF-8 for the app role.
 */
final class LcMessagesDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new LcMessagesCheck(new FakeLcMessagesProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        $probe = new FakeLcMessagesProbe;
        $probe->appRole = 'de_DE.UTF-8';

        return new LcMessagesCheck($probe);
    }
}
