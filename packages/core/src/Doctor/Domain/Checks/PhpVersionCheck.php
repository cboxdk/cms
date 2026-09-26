<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Probes\RuntimeProbe;
use Override;

/**
 * PHP 8.5 or newer (GUARDRAILS 1).
 */
#[Internal]
final readonly class PhpVersionCheck implements DoctorCheck
{
    public const string ID = 'php.version';

    public const string CODE = 'doctor_php_version';

    public const string MINIMUM = '8.5.0';

    public function __construct(private RuntimeProbe $runtime) {}

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
        $version = $this->runtime->phpVersion();

        if (version_compare($version, self::MINIMUM, '>=')) {
            return CheckResult::pass($this->id(), true, sprintf('PHP %s meets the minimum, PHP 8.5.', $version));
        }

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'Cbox CMS needs PHP 8.5 or newer.',
            sprintf('This process runs PHP %s.', $version),
            'Run the application on PHP 8.5, for example on the php-baseimages 8.5 images.',
        );
    }
}
