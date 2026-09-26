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
 * Every table with row level security also has FORCE ROW LEVEL SECURITY (PRD 4.2), so the policies
 * hold for the table's owner as well: a migration or a maintenance job that runs as the owner role
 * reads and writes only the rows the policies allow.
 */
#[Internal]
final readonly class RowSecurityCheck implements DoctorCheck
{
    public const string ID = 'postgres.row_security';

    public const string CODE = 'doctor_row_security_not_forced';

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
            $security = $this->postgres->rowSecurity();
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), true, 'the row level security of the tables', $failed);
        }

        if ($security->unforcedCount === 0) {
            return CheckResult::pass($this->id(), true, $security->enabledCount === 0
                ? sprintf('No table in the database %s has row level security yet.', $security->database)
                : sprintf('All %d tables with row level security in the database %s force it on their owner.', $security->enabledCount, $security->database));
        }

        $tables = implode(', ', $security->unforcedTables);

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'A table has row level security without FORCE ROW LEVEL SECURITY, so its owner skips the policies. Every table with row level security must force it.',
            sprintf(
                '%d of the %d tables with row level security in the database %s do not force it, such as %s.',
                $security->unforcedCount,
                $security->enabledCount,
                $security->database,
                $tables,
            ),
            sprintf(
                'Add a migration that runs ALTER TABLE ... FORCE ROW LEVEL SECURITY as the owner role on each of them, such as ALTER TABLE %s FORCE ROW LEVEL SECURITY, and on their existing partitions.',
                $security->unforcedTables[0] ?? $tables,
            ),
        );
    }
}
