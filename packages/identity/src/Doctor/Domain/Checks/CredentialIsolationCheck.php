<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresQueryFailure;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresReachableCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresRole;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleMembership;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use Cbox\Cms\Identity\Doctor\Domain\Probes\CredentialStoreProbe;
use Override;

/**
 * The credential store is isolated by privilege (PRD 5.16, "Lokale konti", 4.2): its schema
 * exists, the app role has no privilege on the schema or on a relation in it, directly, through a
 * membership or through PUBLIC, and the identity role reaches nothing more than its grants: it is
 * not a superuser, has no BYPASSRLS or CREATEROLE, and is a member of no role that gives more
 * power, as RoleMembership defines it for the app role.
 */
#[Internal]
final readonly class CredentialIsolationCheck implements DoctorCheck
{
    public const string ID = 'identity.credential_isolation';

    public const string CODE_MISSING = 'doctor_credential_store_missing';

    public const string CODE_READABLE = 'doctor_credential_store_readable';

    public const string CODE_PRIVILEGED = 'doctor_identity_role_privileged';

    public function __construct(private CredentialStoreProbe $store) {}

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
        return [new CheckId(PostgresReachableCheck::ID), new CheckId(IdentityConnectionCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $owner = $this->store->schemaOwner();
            $privileges = $owner === null ? [] : $this->store->appPrivileges();
            $role = $this->store->identityRole();
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), true, 'the privileges on the credential store', $failed);
        }

        if ($owner === null) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_MISSING,
                'The schema of the credential store does not exist, so the local accounts cannot keep credentials.',
                sprintf('The database has no schema %s.', CredentialStore::SCHEMA),
                sprintf('Create the schema %s as docs/security/credential-store.md says, then run the migrations.', CredentialStore::SCHEMA),
            );
        }

        if ($privileges !== []) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_READABLE,
                'The app role, which the web and queue processes log in as, has a privilege on the credential store, so it could read or change credentials.',
                sprintf('The app role has %s.', implode(', ', $privileges)),
                sprintf('Revoke the privileges from the app role, from PUBLIC and from the roles the app role is a member of, as in REVOKE ALL ON SCHEMA %1$s FROM <role> and REVOKE ALL ON ALL TABLES IN SCHEMA %1$s FROM <role>. Only the identity role is granted the schema.', CredentialStore::SCHEMA),
            );
        }

        $powers = $this->powers($role);

        if ($powers !== []) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_PRIVILEGED,
                'The identity role reaches more than the credential store, so a process with its credentials could skip row level security or reach other data.',
                sprintf('The identity role %s %s.', $role->name, implode('; ', $powers)),
                sprintf('As a superuser, run ALTER ROLE %s NOSUPERUSER NOBYPASSRLS NOCREATEROLE and revoke the memberships named, so the role keeps only its grants on %s.', $role->name, CredentialStore::SCHEMA),
            );
        }

        return CheckResult::pass($this->id(), true, sprintf(
            'The app role has no privilege on %s, and the identity role %s is not a superuser, has NOBYPASSRLS and NOCREATEROLE, and is a member of no role with more power.',
            CredentialStore::SCHEMA,
            $role->name,
        ));
    }

    /**
     * @return list<string>
     */
    private function powers(PostgresRole $role): array
    {
        $attributes = array_keys(array_filter([
            'SUPERUSER' => $role->superuser,
            'BYPASSRLS' => $role->bypassRowSecurity,
            'CREATEROLE' => $role->createRole,
        ]));
        $powers = $attributes === [] ? [] : ['has '.implode(' and ', $attributes)];

        if ($role->memberships !== []) {
            $powers[] = 'is a member of '.implode(', ', array_map(
                static fn (RoleMembership $membership): string => $membership->name,
                $role->memberships,
            ));
        }

        return $powers;
    }
}
