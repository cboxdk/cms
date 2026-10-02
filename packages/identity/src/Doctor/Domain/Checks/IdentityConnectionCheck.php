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
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Identity\Doctor\Domain\Probes\CredentialStoreProbe;
use Override;

/**
 * The identity connection of the credential store answers, and it logs in as a role of its own
 * (PRD 5.16, "Lokale konti"): not the app role, which the web and queue processes use for
 * everything else, and not the owner role, which owns the schema, so the credentials are reached
 * only through the identity role. The owner role is the one cbox-cms.doctor names, and the owner of
 * the credential store's schema, whichever the doctor knows.
 */
#[Internal]
final readonly class IdentityConnectionCheck implements DoctorCheck
{
    public const string ID = 'identity.connection';

    public const string CODE_UNAVAILABLE = 'doctor_identity_connection_unavailable';

    public const string CODE_REFUSED = 'doctor_identity_connection_refused';

    public const string CODE_SHARED_ROLE = 'doctor_identity_connection_shared_role';

    public function __construct(
        private CredentialStoreProbe $store,
        private DoctorSettings $settings,
    ) {}

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
        $target = $this->store->target();

        try {
            $login = $this->store->identityLogin();
        } catch (ProbeFailed $failed) {
            return $failed->kind === FailureKind::Unavailable
                ? CheckResult::fail(
                    $this->id(),
                    true,
                    FailureKind::Unavailable,
                    self::CODE_UNAVAILABLE,
                    sprintf('Postgres cannot be reached on the identity connection, %s, right now.', $target),
                    $failed->cause,
                    'Start Postgres, or point the identity connection at a running server, and run cms:doctor again.',
                )
                : CheckResult::fail(
                    $this->id(),
                    true,
                    FailureKind::Violation,
                    self::CODE_REFUSED,
                    sprintf('The identity connection, %s, could not log in.', $target),
                    $failed->cause,
                    'Configure the connection that cbox-cms.identity.connection names as Postgres, with the database of the app role\'s connection and the identity role\'s username and password (docs/security/credential-store.md).',
                );
        }

        try {
            $app = $this->store->appLogin();
            $owners = array_values(array_unique(array_filter([$this->settings->ownerRole, $this->store->schemaOwner()], is_string(...))));
        } catch (ProbeFailed $failed) {
            return PostgresQueryFailure::result($this->id(), true, 'the roles of the app connection and the credential store', $failed);
        }

        if ($login === $app || in_array($login, $owners, true)) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE_SHARED_ROLE,
                'The identity connection logs in as a role the application already uses for other work, so the credential store is not isolated from it.',
                sprintf('The identity connection, %s, logs in as %s, the %s.', $target, $login, $login === $app ? 'app role' : 'owner role'),
                'Create a role of its own for the credential store, give it the schema cms_identity as docs/security/credential-store.md says, and point the identity connection at it.',
            );
        }

        return CheckResult::pass($this->id(), true, sprintf('Connected to the credential store as %s, a role of its own.', $target));
    }
}
