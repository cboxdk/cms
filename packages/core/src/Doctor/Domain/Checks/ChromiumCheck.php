<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\ToolProbe;
use Override;

/**
 * --dev: the Chromium that the installed Playwright launches has been downloaded, for the browser
 * tests (gate 8 of GUARDRAILS 10).
 */
#[Internal]
final readonly class ChromiumCheck implements DoctorCheck
{
    public const string ID = 'dev.chromium';

    public const string CODE = 'doctor_chromium_missing';

    public function __construct(private ToolProbe $tools) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return false;
    }

    #[Override]
    public function requires(): array
    {
        return [new CheckId(PlaywrightCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $executable = $this->tools->chromiumExecutable();
        } catch (ProbeFailed $failed) {
            return CheckResult::fail(
                $this->id(),
                false,
                $failed->kind,
                self::CODE,
                'The browser tests need the Chromium build that the installed Playwright was made for.',
                $failed->cause,
                'Run npx playwright install chromium in the project, and again after Playwright\'s version in package.json changes.',
            );
        }

        return CheckResult::pass($this->id(), false, sprintf('Playwright\'s Chromium is installed at %s.', $executable));
    }
}
