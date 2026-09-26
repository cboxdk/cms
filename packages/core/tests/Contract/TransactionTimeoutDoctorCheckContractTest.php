<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\TransactionTimeoutCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Closure;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against TransactionTimeoutCheck, with fake probes: transaction_timeout 5 s on the role against none.
 */
final class TransactionTimeoutDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new TransactionTimeoutCheck(new FakePostgresProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new TransactionTimeoutCheck($this->postgres(static function (FakePostgresProbe $probe): void {
            $probe->transactionTimeoutMs = 0;
            $probe->transactionTimeoutSource = 'default';
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
