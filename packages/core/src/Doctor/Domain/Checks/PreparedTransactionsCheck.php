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
 * max_prepared_transactions = 0 (PRD 4.2): a forgotten prepared transaction holds its locks and
 * the horizon until someone finds it, and no transaction_timeout ends it.
 */
#[Internal]
final readonly class PreparedTransactionsCheck implements DoctorCheck
{
    public const string ID = 'postgres.prepared_transactions';

    public const string CODE = 'doctor_prepared_transactions_enabled';

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
            $prepared = $this->postgres->maxPreparedTransactions();
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), true, 'max_prepared_transactions', $failed);
        }

        if ($prepared === 0) {
            return CheckResult::pass($this->id(), true, 'max_prepared_transactions is 0, so no transaction can be left prepared.');
        }

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'Prepared transactions are enabled. One that is left behind keeps its locks and holds back the horizon, and no timeout ends it.',
            sprintf('max_prepared_transactions is %d.', $prepared),
            'Set max_prepared_transactions = 0 in the server configuration and restart Postgres.',
        );
    }
}
