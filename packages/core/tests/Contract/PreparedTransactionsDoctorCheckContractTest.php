<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PreparedTransactionsCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Closure;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against PreparedTransactionsCheck, with fake probes: max_prepared_transactions 0 against 10.
 */
final class PreparedTransactionsDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new PreparedTransactionsCheck(new FakePostgresProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new PreparedTransactionsCheck($this->postgres(static function (FakePostgresProbe $probe): void {
            $probe->maxPreparedTransactions = 10;
        }));
    }

    /**
     * @param  Closure(FakePostgresProbe): void  $change
     */
    private function postgres(Closure $change): FakePostgresProbe
    {
        $probe = new FakePostgresProbe;
        $change($probe);

        return $probe;
    }
}
