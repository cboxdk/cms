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
 * --dev: the playwright package is installed in the project's node_modules, for the browser tests
 * (gate 8 of GUARDRAILS 10).
 */
#[Internal]
final readonly class PlaywrightCheck implements DoctorCheck
{
    public const string ID = 'dev.playwright';

    public const string CODE = 'doctor_playwright_missing';

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
        return [new CheckId(NodeCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $version = $this->tools->playwrightVersion();
        } catch (ProbeFailed $failed) {
            return CheckResult::fail(
                $this->id(),
                false,
                $failed->kind,
                self::CODE,
                'The browser tests need Playwright from the project\'s package.json.',
                $failed->cause,
                'Run npm ci in the project.',
            );
        }

        return CheckResult::pass($this->id(), false, sprintf('Playwright %s is installed in the project.', $version));
    }
}
