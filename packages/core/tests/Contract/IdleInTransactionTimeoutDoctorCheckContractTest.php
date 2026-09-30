<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\IdleInTransactionTimeoutCheck;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against IdleInTransactionTimeoutCheck, with fake probes: idle_in_transaction_session_timeout 5 s on the role against none.
 */
final class IdleInTransactionTimeoutDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new IdleInTransactionTimeoutCheck(new FakePostgresProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        $probe = new FakePostgresProbe;
        $probe->idleInTransactionTimeoutMs = 0;
        $probe->idleInTransactionTimeoutSource = SettingSource::Default;

        return new IdleInTransactionTimeoutCheck($probe);
    }
}
