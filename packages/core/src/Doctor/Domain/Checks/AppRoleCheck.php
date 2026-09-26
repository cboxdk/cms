<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleMembership;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Override;

/**
 * The app role is not a superuser and has NOBYPASSRLS, so row level security applies to it
 * (PRD 4.2, GUARDRAILS 6). Nor is it a member, directly or through other roles, of a superuser, a
 * role with BYPASSRLS or a role that owns relations: SET ROLE reaches the attributes of such a
 * role, and the owner of a table can turn its row level security off.
 */
#[Internal]
final readonly class AppRoleCheck implements DoctorCheck
{
    public const string ID = 'postgres.app_role';

    public const string CODE_SUPERUSER = 'doctor_app_role_superuser';

    public const string CODE_BYPASSRLS = 'doctor_app_role_bypassrls';

    public const string CODE_MEMBERSHIP = 'doctor_app_role_privileged_membership';

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

        if ($role->memberships !== []) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_MEMBERSHIP,
                'The app role is a member of a role with more power: it can SET ROLE to a superuser or a BYPASSRLS role and skip row level security, or act as the owner of tables and alter, drop or turn off row level security on them.',
                sprintf('The role %s is a member of %s.', $role->name, implode('; ', array_map($this->describe(...), $role->memberships))),
                sprintf(
                    'Run REVOKE %s FROM %s as a superuser, or revoke the grant that leads to it when the membership is indirect. The app role reads and writes rows only.',
                    implode(', ', array_map(static fn (RoleMembership $membership): string => $membership->name, $role->memberships)),
                    $role->name,
                ),
            );
        }

        return CheckResult::pass($this->id(), true, sprintf(
            'The app role %s is not a superuser, has NOBYPASSRLS and is not a member of a superuser, a BYPASSRLS role or a role that owns relations.',
            $role->name,
        ));
    }

    private function describe(RoleMembership $membership): string
    {
        $attributes = array_keys(array_filter(['SUPERUSER' => $membership->superuser, 'BYPASSRLS' => $membership->bypassRowSecurity]));
        $powers = $attributes === [] ? [] : ['has '.implode(' and ', $attributes)];

        if ($membership->ownsRelations) {
            $powers[] = 'owns relations';
        }

        return sprintf('%s, which %s', $membership->name, implode(' and ', $powers));
    }
}
