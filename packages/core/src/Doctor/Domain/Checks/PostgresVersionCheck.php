<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Override;

/**
 * Postgres 17 or newer (PRD 3.3, GUARDRAILS 1).
 */
#[Internal]
final readonly class PostgresVersionCheck implements DoctorCheck
{
    public const string ID = 'postgres.version';

    public const string CODE = 'doctor_postgres_version';

    public const int MINIMUM_MAJOR = 17;

    public function __construct(private PostgresProbe $postgres) {}

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
        return [new CheckId(PostgresReachableCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $version = $this->postgres->version();
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), true, 'the server version', $failed);
        }

        if ($version->major() >= self::MINIMUM_MAJOR) {
            return CheckResult::pass($this->id(), true, sprintf('Postgres %s meets the minimum, Postgres 17.', $version->text));
        }

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'Cbox CMS needs Postgres 17 or newer: it relies on transaction_timeout and other features that arrived in 17.',
            sprintf('The server runs Postgres %s (server_version_num %d).', $version->text, $version->number),
            'Upgrade the server to Postgres 17 or newer, for example with pg_upgrade, or point the connection at a server that runs it.',
        );
    }
}
