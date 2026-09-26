<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\DdlPrivilegesCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Closure;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against DdlPrivilegesCheck, with fake probes: an app role without DDL against one that owns a table.
 */
final class DdlPrivilegesDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new DdlPrivilegesCheck(new FakePostgresProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new DdlPrivilegesCheck($this->postgres(static function (FakePostgresProbe $probe): void {
            $probe->ownedRelations = ['cms.receipts'];
            $probe->schemasWithCreate = ['cms'];
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
