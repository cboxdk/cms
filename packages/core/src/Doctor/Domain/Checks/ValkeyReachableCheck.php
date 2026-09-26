<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\ValkeyProbe;
use Override;

/**
 * Valkey answers PING on the application's Redis connection (PRD 3.3, GUARDRAILS 1).
 */
#[Internal]
final readonly class ValkeyReachableCheck implements DoctorCheck
{
    public const string ID = 'valkey.reachable';

    public const string CODE_UNAVAILABLE = 'doctor_valkey_unavailable';

    public const string CODE_REFUSED = 'doctor_valkey_refused';

    public function __construct(private ValkeyProbe $valkey) {}

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
        $target = $this->valkey->target();

        try {
            $this->valkey->ping();
        } catch (ProbeFailed $failed) {
            return $failed->kind === FailureKind::Unavailable
                ? CheckResult::fail(
                    $this->id(),
                    true,
                    FailureKind::Unavailable,
                    self::CODE_UNAVAILABLE,
                    sprintf('Valkey cannot be reached on %s right now.', $target),
                    $failed->cause,
                    'Start Valkey, or point REDIS_HOST and REDIS_PORT at a running server, and run cms:doctor again.',
                )
                : CheckResult::fail(
                    $this->id(),
                    true,
                    FailureKind::Violation,
                    self::CODE_REFUSED,
                    sprintf('Valkey on %s answered but refused the connection.', $target),
                    $failed->cause,
                    'Check REDIS_USERNAME and REDIS_PASSWORD, and that the PHP redis extension is installed.',
                );
        }

        return CheckResult::pass($this->id(), true, sprintf('Valkey answered PING on %s.', $target));
    }
}
