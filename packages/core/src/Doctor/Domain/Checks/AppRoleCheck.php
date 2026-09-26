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
 * The app role is not a superuser and has NOBYPASSRLS, so row level security applies to it
 * (PRD 4.2, GUARDRAILS 6).
 */
#[Internal]
final readonly class AppRoleCheck implements DoctorCheck
{
    public const string ID = 'postgres.app_role';

    public const string CODE_SUPERUSER = 'doctor_app_role_superuser';

    public const string CODE_BYPASSRLS = 'doctor_app_role_bypassrls';

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
            $role = $this->postgres->role();
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), true, 'the attributes of the app role', $failed);
        }

        if ($role->superuser) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_SUPERUSER,
                'The application connects to Postgres as a superuser. A superuser skips row level security and every privilege, so classification and grants would not hold.',
                sprintf('The role %s has SUPERUSER.', $role->name),
                sprintf('Connect as a role without superuser, or run ALTER ROLE %s NOSUPERUSER as a superuser.', $role->name),
            );
        }

        if ($role->bypassRowSecurity) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_BYPASSRLS,
                'The app role bypasses row level security, so a read without an actor context would see every row.',
                sprintf('The role %s has BYPASSRLS.', $role->name),
                sprintf('Run ALTER ROLE %s NOBYPASSRLS as a superuser.', $role->name),
            );
        }

        return CheckResult::pass($this->id(), true, sprintf('The app role %s is not a superuser and has NOBYPASSRLS.', $role->name));
    }
}
