<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;
use Override;

/**
 * allow_url_fopen is off (GUARDRAILS 3): outbound HTTP goes only through the egress gateway and
 * its SSRF guard. With the setting on, PHP's URL wrappers make fopen, file, copy, SplFileObject,
 * DOMDocument::load and every other function that opens a file name fetch http:// and ftp:// URLs,
 * which the Arch suite can only rule out for the calls it sees.
 */
#[Internal]
final readonly class AllowUrlFopenCheck implements DoctorCheck
{
    public const string ID = 'php.allow_url_fopen';

    public const string CODE = 'doctor_php_allow_url_fopen';

    public function __construct(private PhpSettingsProbe $settings) {}

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
        if (! $this->settings->allowUrlFopen()) {
            return CheckResult::pass($this->id(), true, 'allow_url_fopen is off, so the file functions open no URLs.');
        }

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'Outbound HTTP may only go through the egress gateway and its SSRF guard, so PHP must not open URLs as files.',
            'allow_url_fopen is on in this process: fopen, file, copy, SplFileObject and the other file functions fetch http:// and ftp:// URLs.',
            'Set allow_url_fopen = Off in the php.ini of every process that runs Cbox CMS, for example in a conf.d file, or run PHP with -d allow_url_fopen=0.',
        );
    }
}
