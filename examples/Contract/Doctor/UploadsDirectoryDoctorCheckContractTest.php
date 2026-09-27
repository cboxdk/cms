<?php

declare(strict_types=1);

namespace Examples\Contract\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck suite against UploadsDirectoryCheck: passing on the system's temporary
 * directory, which exists and is writable, and failing on a directory that does not exist. The
 * class also tests the check's own code for the failure.
 */
final class UploadsDirectoryDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new UploadsDirectoryCheck(sys_get_temp_dir());
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new UploadsDirectoryCheck($this->missingDirectory());
    }

    #[Test]
    public function a_missing_directory_is_a_violation_that_names_the_directory(): void
    {
        $directory = $this->missingDirectory();
        $result = new UploadsDirectoryCheck($directory)->run();

        self::assertSame(CheckStatus::Fail, $result->status);
        self::assertSame(UploadsDirectoryCheck::CODE_MISSING, $result->code);
        self::assertStringContainsString($directory, (string) $result->cause);
        self::assertFalse($result->blocking);
    }

    private function missingDirectory(): string
    {
        return sys_get_temp_dir().'/cbox-cms-example-uploads-that-do-not-exist';
    }
}
