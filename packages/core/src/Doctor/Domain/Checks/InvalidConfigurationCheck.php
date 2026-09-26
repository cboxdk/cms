<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Override;

/**
 * Stands in for every other check when `cms.doctor` itself is invalid, so cms:doctor still prints
 * its document and exits with the violation code instead of stopping with an exception.
 */
#[Internal]
final readonly class InvalidConfigurationCheck implements DoctorCheck
{
    public const string ID = 'doctor.config';

    public const string CODE = 'doctor_config_invalid';

    public function __construct(private string $cause) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return true;
    }

    #[Override]
    public function requires(): array
    {
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'The settings of cms:doctor are invalid, so it cannot check the installation.',
            $this->cause,
            'Fix the setting in config/cms.php, or remove it to use the default.',
        );
    }
}
