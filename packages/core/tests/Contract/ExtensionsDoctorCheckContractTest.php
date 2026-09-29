<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ExtensionsCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Closure;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against ExtensionsCheck, with fake probes: a database with ltree against one without it.
 */
final class ExtensionsDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new ExtensionsCheck(new FakePostgresProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new ExtensionsCheck($this->postgres(static function (FakePostgresProbe $probe): void {
            $probe->extensions = ['plpgsql'];
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
