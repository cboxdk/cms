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
 * idle_in_transaction_session_timeout is set on the app role (PRD 7.4): a session that begins a
 * transaction and then waits, for a lost client or a call to another service, is ended by the
 * server, so it cannot hold its locks, the event horizon and vacuum. It blocks, as
 * postgres.transaction_timeout does, whose rule it follows.
 *
 * It reads the value of a new session and where it came from. It passes only when the value is
 * above zero and comes from the role, with ALTER ROLE ... SET or ALTER ROLE ... IN DATABASE ...
 * SET. A value from the server configuration would also reach the owner role; a value from the
 * connection's options hides whether the role has one.
 */
#[Internal]
final readonly class IdleInTransactionTimeoutCheck implements DoctorCheck
{
    public const string ID = 'postgres.idle_in_transaction_timeout';

    public const string CODE = 'doctor_idle_in_transaction_timeout_missing';

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
        return [new CheckId(PostgresVersionCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $timeout = $this->postgres->idleInTransactionTimeout();
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), true, 'idle_in_transaction_session_timeout', $failed);
        }

        if ($timeout->milliseconds > 0 && $timeout->isSetOnRole()) {
            return CheckResult::pass($this->id(), true, sprintf(
                'idle_in_transaction_session_timeout is %d ms on the app role %s.',
                $timeout->milliseconds,
                $timeout->role,
            ));
        }

        $cause = $timeout->milliseconds === 0
            ? sprintf('idle_in_transaction_session_timeout is 0 (off) for the role %s; Postgres took the value from "%s".', $timeout->role, $timeout->source->value)
            : sprintf('idle_in_transaction_session_timeout is %d ms for the role %s, but Postgres took it from "%s", not from the role.', $timeout->milliseconds, $timeout->role, $timeout->source->value);

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'The app role has no idle_in_transaction_session_timeout, so a session that begins a transaction and then waits holds its locks, the event horizon and vacuum until something else ends it.',
            $cause,
            sprintf("Run ALTER ROLE %s SET idle_in_transaction_session_timeout = '5s' as a superuser, and remove idle_in_transaction_session_timeout from the connection's options.", $timeout->role),
        );
    }
}
