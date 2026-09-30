<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\OldestTransactionCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\HeldTransaction;
use Cbox\Cms\Core\Doctor\Domain\Dto\OpenTransactions;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against OldestTransactionCheck, with fake probes: a transaction id held for 40 ms against one held for 9 s.
 */
final class OldestTransactionDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        $probe = new FakePostgresProbe;
        $probe->openTransactions = new OpenTransactions(new HeldTransaction(4711, 'cms_app', 40), new HeldTransaction(4711, 'cms_app', 40));

        return new OldestTransactionCheck($probe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        $probe = new FakePostgresProbe;
        $probe->openTransactions = new OpenTransactions(new HeldTransaction(4711, 'cms_app', 9000), null);

        return new OldestTransactionCheck($probe);
    }
}
