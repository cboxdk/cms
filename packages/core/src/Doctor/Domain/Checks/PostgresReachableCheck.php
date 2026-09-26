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
 * Postgres answers on the app role's connection (PRD 4.2). The other Postgres checks require it.
 */
#[Internal]
final readonly class PostgresReachableCheck implements DoctorCheck
{
    public const string ID = 'postgres.reachable';

    public const string CODE_UNAVAILABLE = 'doctor_postgres_unavailable';

    public const string CODE_REFUSED = 'doctor_postgres_refused';

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
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        $target = $this->postgres->target();

        try {
            $this->postgres->connect();
        } catch (ProbeFailed $failed) {
            return $failed->kind === FailureKind::Unavailable
                ? CheckResult::fail(
                    $this->id(),
                    true,
                    FailureKind::Unavailable,
                    self::CODE_UNAVAILABLE,
                    sprintf('Postgres cannot be reached at %s right now.', $target),
                    $failed->cause,
                    'Start Postgres, or point DB_HOST and DB_PORT at a running server, and run cms:doctor again. When Postgres is starting, wait and try again.',
                )
                : CheckResult::fail(
                    $this->id(),
                    true,
                    FailureKind::Violation,
                    self::CODE_REFUSED,
                    sprintf('Postgres at %s answered but refused the connection.', $target),
                    $failed->cause,
                    'Check DB_DATABASE, DB_USERNAME and DB_PASSWORD against the database and the app role that exist on the server.',
                );
        }

        return CheckResult::pass($this->id(), true, sprintf('Connected to Postgres as %s.', $target));
    }
}
