<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\ToolProbe;
use Override;

/**
 * --dev: Node on PATH, at least the configured minimum (GUARDRAILS 7.1). The JS gates, the
 * generators' TypeScript and the browser tests need it.
 */
#[Internal]
final readonly class NodeCheck implements DoctorCheck
{
    public const string ID = 'dev.node';

    public const string CODE_MISSING = 'doctor_node_missing';

    public const string CODE_VERSION = 'doctor_node_version';

    public function __construct(
        private ToolProbe $tools,
        private string $minimum,
    ) {}

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
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $version = $this->tools->nodeVersion();
        } catch (ProbeFailed $failed) {
            return CheckResult::fail(
                $this->id(),
                false,
                $failed->kind,
                self::CODE_MISSING,
                'Node is needed for development: the JS gates, the generated TypeScript and the browser tests run on it.',
                $failed->cause,
                sprintf('Install Node %s or newer and put it on PATH, for example with nvm or Herd.', $this->minimum),
            );
        }

        if (version_compare($version, $this->minimum, '>=')) {
            return CheckResult::pass($this->id(), false, sprintf('Node %s is on PATH and meets the minimum, %s.', $version, $this->minimum));
        }

        return CheckResult::fail(
            $this->id(),
            false,
            FailureKind::Violation,
            self::CODE_VERSION,
            sprintf('Development needs Node %s or newer.', $this->minimum),
            sprintf('The node on PATH is version %s.', $version),
            sprintf('Install Node %s or newer and put it first on PATH.', $this->minimum),
        );
    }
}
