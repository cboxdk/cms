<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\AppRoleCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Closure;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against AppRoleCheck, with fake probes: an app role with NOBYPASSRLS against one with BYPASSRLS.
 */
final class AppRoleDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new AppRoleCheck(new FakePostgresProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new AppRoleCheck($this->postgres(static function (FakePostgresProbe $probe): void {
            $probe->bypassRowSecurity = true;
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
