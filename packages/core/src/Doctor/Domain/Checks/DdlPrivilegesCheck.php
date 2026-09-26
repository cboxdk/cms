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
 * The app role has no DDL (PRD 4.2, GUARDRAILS 6): it owns no tables, views or sequences, and has
 * no CREATE on the database or on any schema outside the system schemas. Migrations run as the
 * separate owner role.
 */
#[Internal]
final readonly class DdlPrivilegesCheck implements DoctorCheck
{
    public const string ID = 'postgres.ddl_privileges';

    public const string CODE = 'doctor_app_role_has_ddl';

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
            $ddl = $this->postgres->ddlPrivileges();
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), true, 'the privileges of the app role', $failed);
        }

        $causes = [];
        $fixes = [];

        if ($ddl->ownedCount > 0) {
            $causes[] = sprintf('it owns %d relations, such as %s', $ddl->ownedCount, implode(', ', $ddl->ownedRelations));
            $fixes[] = sprintf('give them to the owner role with ALTER TABLE ... OWNER TO, or REASSIGN OWNED BY %s TO the owner role', $ddl->role);
        }

        if ($ddl->createOnDatabase) {
            $causes[] = sprintf('it has CREATE on the database %s', $ddl->database);
            $fixes[] = sprintf('REVOKE CREATE ON DATABASE %s FROM %s, and from PUBLIC', $ddl->database, $ddl->role);
        }

        if ($ddl->schemasWithCreate !== []) {
            $causes[] = sprintf('it has CREATE on the schemas %s', implode(', ', $ddl->schemasWithCreate));
            $fixes[] = sprintf('REVOKE CREATE ON SCHEMA %s FROM %s, and from PUBLIC', implode(', ', $ddl->schemasWithCreate), $ddl->role);
        }

        if ($causes === []) {
            return CheckResult::pass($this->id(), true, sprintf(
                'The app role %s owns nothing and cannot create objects in the database %s or its schemas.',
                $ddl->role,
                $ddl->database,
            ));
        }

        return CheckResult::fail(
            $this->id(),
            true,
            FailureKind::Violation,
            self::CODE,
            'The app role can change the schema. Only the owner role may run DDL; the app role reads and writes rows.',
            sprintf('The role %s: %s.', $ddl->role, implode('; ', $causes)),
            ucfirst(implode('; ', $fixes)).'. Run the migrations as the owner role.',
        );
    }
}
