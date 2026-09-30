<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Dto\HeldTransaction;
use Cbox\Cms\Core\Doctor\Domain\Dto\OpenTransactions;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Override;

/**
 * The two alarms of the horizon (PRD 4.2, 7.4, 7.12): the age of the oldest transaction that holds
 * a transaction id, which holds the event horizon, so no subscriber sees an event committed after
 * it began; and the age of the oldest snapshot, which holds vacuum. Each fails when it is older
 * than its limit, by default the command budget of 5 s (PRD 7.4): command transactions are capped
 * there and background work runs in transactions under 2 s, so anything older is outside every
 * budget. It does not block: the kernel must start, and the transaction may end by itself.
 *
 * The age is the time since the transaction began, an upper bound, because Postgres records
 * neither when it assigned the id nor when it took the snapshot. The app role sees that time only
 * for its own sessions; the sessions of other roles that hold an id or a snapshot are named in the
 * explanation without an age, and never fail the check.
 */
#[Internal]
final readonly class OldestTransactionCheck implements DoctorCheck
{
    public const string ID = 'postgres.oldest_xact';

    public const string CODE_HORIZON = 'doctor_horizon_held';

    public const string CODE_SNAPSHOT = 'doctor_snapshot_held';

    /** The command budget of PRD 7.4, which transaction_timeout enforces on the app role. */
    public const int DEFAULT_LIMIT_MILLISECONDS = 5000;

    public function __construct(
        private PostgresProbe $postgres,
        private int $xidLimitMilliseconds = self::DEFAULT_LIMIT_MILLISECONDS,
        private int $snapshotLimitMilliseconds = self::DEFAULT_LIMIT_MILLISECONDS,
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
        return [new CheckId(PostgresReachableCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $open = $this->postgres->openTransactions();
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), false, 'the open transactions', $failed);
        }

        $xidHeld = $open->oldestXid instanceof HeldTransaction && $open->oldestXid->milliseconds > $this->xidLimitMilliseconds ? $open->oldestXid : null;
        $snapshotHeld = $open->oldestSnapshot instanceof HeldTransaction && $open->oldestSnapshot->milliseconds > $this->snapshotLimitMilliseconds ? $open->oldestSnapshot : null;

        if (! $xidHeld instanceof HeldTransaction && ! $snapshotHeld instanceof HeldTransaction) {
            return CheckResult::pass($this->id(), false, $this->describe($open));
        }

        $causes = [];

        if ($xidHeld instanceof HeldTransaction) {
            $causes[] = sprintf('The session %s has held a transaction id for up to %d ms, above the limit of %d ms.', $this->session($xidHeld), $xidHeld->milliseconds, $this->xidLimitMilliseconds);
        }

        if ($snapshotHeld instanceof HeldTransaction) {
            $causes[] = sprintf('The session %s has held a snapshot for up to %d ms, above the limit of %d ms.', $this->session($snapshotHeld), $snapshotHeld->milliseconds, $this->snapshotLimitMilliseconds);
        }

        return CheckResult::fail(
            $this->id(),
            false,
            FailureKind::Violation,
            $xidHeld instanceof HeldTransaction ? self::CODE_HORIZON : self::CODE_SNAPSHOT,
            $xidHeld instanceof HeldTransaction
                ? 'A transaction holds back the event horizon: no subscriber sees an event committed after it began until it ends.'
                : 'A snapshot holds back vacuum: the rows changed after it was taken cannot be removed until it ends.',
            implode(' ', $causes),
            'Find what keeps the transaction open, such as a call to another service or a long report, and end it; pg_terminate_backend(<pid>), run as its role or a superuser, ends one that hangs. Run long reads on a replica, never on the primary.',
        );
    }

    private function describe(OpenTransactions $open): string
    {
        $parts = [
            $open->oldestXid instanceof HeldTransaction
                ? sprintf('the oldest transaction that holds a transaction id has run for %d ms (session %s)', $open->oldestXid->milliseconds, $this->session($open->oldestXid))
                : 'no transaction the app role can see holds a transaction id',
            $open->oldestSnapshot instanceof HeldTransaction
                ? sprintf('the oldest that holds a snapshot in this database for %d ms (session %s)', $open->oldestSnapshot->milliseconds, $this->session($open->oldestSnapshot))
                : 'none holds a snapshot in this database',
        ];

        $explanation = sprintf(
            'Within the limits of %d ms for a transaction id and %d ms for a snapshot: %s.',
            $this->xidLimitMilliseconds,
            $this->snapshotLimitMilliseconds,
            implode(', and ', $parts),
        );

        if ($open->unmeasured > 0) {
            $explanation .= sprintf(
                ' %d %s of the %s %s %s a transaction id or a snapshot; the app role cannot see for how long.',
                $open->unmeasured,
                $open->unmeasured === 1 ? 'session' : 'sessions',
                count($open->unmeasuredRoles) === 1 ? 'role' : 'roles',
                implode(', ', $open->unmeasuredRoles),
                $open->unmeasured === 1 ? 'holds' : 'hold',
            );
        }

        return $explanation;
    }

    private function session(HeldTransaction $held): string
    {
        return sprintf('pid %d of the role %s', $held->pid, $held->role);
    }
}
