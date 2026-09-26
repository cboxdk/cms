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
 * Laravel 13, the one major the kernel follows (PRD 3.4, GUARDRAILS 1).
 */
#[Internal]
final readonly class LaravelVersionCheck implements DoctorCheck
{
    public const string ID = 'laravel.version';

    public const string CODE = 'doctor_laravel_version';

    public const int MAJOR = 13;

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
        $version = $this->runtime->laravelVersion();

        if ((int) explode('.', $version)[0] === self::MAJOR) {
            return CheckResult::pass($this->id(), true, sprintf('Laravel %s is the major Cbox CMS follows, Laravel 13.', $version));
        }

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'Cbox CMS runs on Laravel 13 and on no other major.',
            sprintf('The application runs Laravel %s.', $version),
            'Require laravel/framework ^13.0 in composer.json and run composer update.',
        );
    }
}
