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
 * (PRD 4.2, GUARDRAILS 6), and has NOCREATEROLE, so it cannot make or grant roles. Nor is it a
 * member, directly or through other roles, of a role with more power: a superuser, a role with
 * BYPASSRLS or CREATEROLE, a role that owns relations, a role that owns or may create objects in
 * the database or its schemas, or a predefined role that reads or writes every table, reaches the
 * server's files and programs, cancels and terminates other sessions or reads their query text.
 * SET ROLE reaches the attributes of such a role, the owner of a table can turn its row level
 * security off, and a member that inherits gets the role's grants.
 */
#[Internal]
final readonly class AppRoleCheck implements DoctorCheck
{
    public const string ID = 'postgres.app_role';

    public const string CODE_SUPERUSER = 'doctor_app_role_superuser';

    public const string CODE_BYPASSRLS = 'doctor_app_role_bypassrls';

    public const string CODE_CREATEROLE = 'doctor_app_role_createrole';

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

        if ($role->createRole) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_CREATEROLE,
                'The app role can create roles, grant them to itself and alter or drop the roles it administers. Roles and their grants belong to the operator; the app role reads and writes rows only.',
                sprintf('The role %s has CREATEROLE.', $role->name),
                sprintf('Run ALTER ROLE %s NOCREATEROLE as a superuser.', $role->name),
            );
        }

        if ($role->memberships !== []) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_MEMBERSHIP,
                'The app role is a member of a role with more power: it can SET ROLE to a superuser or a BYPASSRLS role and skip row level security, to a CREATEROLE role and make roles, act as the owner of tables and alter, drop or turn off row level security on them, create objects in the database or its schemas, or use a predefined role to read or write every table, reach the server\'s files and programs, cancel and terminate the sessions of other roles, the owner\'s migrations and partition maintenance included, or read the query text of every session.',
                sprintf('The role %s is a member of %s.', $role->name, implode('; ', array_map($this->describe(...), $role->memberships))),
                $this->membershipFix($role->name, $role->memberships),
            );
        }

        return CheckResult::pass($this->id(), true, sprintf(
            'The app role %s is not a superuser, has NOBYPASSRLS and NOCREATEROLE, and is not a member of a superuser, a BYPASSRLS or CREATEROLE role, a role that owns relations or may create objects, or a predefined role that reaches every table or the server, signals other sessions or reads their query text.',
            $role->name,
        ));
    }

    /**
     * pg_database_owner has no explicit members: a role is one while it owns the database, itself
     * or through a role it is a member of, so REVOKE cannot name it.
     *
     * @param  non-empty-list<RoleMembership>  $memberships
     */
    private function membershipFix(string $role, array $memberships): string
    {
        $revocable = array_values(array_filter(
            array_map(static fn (RoleMembership $membership): string => $membership->name, $memberships),
            static fn (string $name): bool => $name !== RoleMembership::DATABASE_OWNER,
        ));
        $fixes = [];

        if ($revocable !== []) {
            $fixes[] = sprintf('Run REVOKE %s FROM %s as a superuser, or revoke the grant that leads to it when the membership is indirect.', implode(', ', $revocable), $role);
        }

        if (count($revocable) < count($memberships)) {
            $fixes[] = sprintf('%s comes from owning the database: give the database to the owner role with ALTER DATABASE ... OWNER TO, or revoke the membership through which %s reaches its owner.', RoleMembership::DATABASE_OWNER, $role);
        }

        return implode(' ', $fixes).' The app role reads and writes rows only.';
    }

    private function describe(RoleMembership $membership): string
    {
        $attributes = array_keys(array_filter([
            'SUPERUSER' => $membership->superuser,
            'BYPASSRLS' => $membership->bypassRowSecurity,
            'CREATEROLE' => $membership->createRole,
        ]));
        $powers = $attributes === [] ? [] : ['has '.implode(' and ', $attributes)];

        if ($membership->ownsRelations) {
            $powers[] = 'owns relations';
        }

        if ($membership->createsObjects) {
            $powers[] = 'owns or may create objects in the database or its schemas';
        }

        $predefined = $membership->predefinedPower();

        if ($predefined !== null) {
            $powers[] = $predefined;
        }

        return sprintf('%s, which %s', $membership->name, implode(' and ', $powers));
    }
}
