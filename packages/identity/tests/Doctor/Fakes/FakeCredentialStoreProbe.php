<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresRole;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleMembership;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Identity\Doctor\Domain\Probes\CredentialStoreProbe;
use Override;

/**
 * A credential store that keeps the operating contract until the test changes a property: the
 * identity connection logs in as cms_identity, the app role is cms_app, the schema cms_identity is
 * the owner role cms_owner's, the app role has no privilege on it, and the identity role has no
 * SUPERUSER, BYPASSRLS or CREATEROLE and no membership of a role with more power. Its target is
 * named fake, so its output never passes for a real server's.
 */
final class FakeCredentialStoreProbe implements CredentialStoreProbe
{
    public ?ProbeFailed $loginFailure = null;

    /** Thrown by every method but target() and identityLogin(). */
    public ?ProbeFailed $queryFailure = null;

    public string $identityLogin = 'cms_identity';

    public string $appLogin = 'cms_app';

    public ?string $schemaOwner = 'cms_owner';

    public bool $superuser = false;

    public bool $bypassRowSecurity = false;

    public bool $createRole = false;

    /** @var list<RoleMembership> */
    public array $memberships = [];

    /** @var list<string> */
    public array $appPrivileges = [];

    #[Override]
    public function target(): string
    {
        return 'cms_identity@fake:5432/cms (connection pgsql_identity)';
    }

    #[Override]
    public function identityLogin(): string
    {
        if ($this->loginFailure instanceof ProbeFailed) {
            throw $this->loginFailure;
        }

        return $this->identityLogin;
    }

    #[Override]
    public function appLogin(): string
    {
        $this->failQuery();

        return $this->appLogin;
    }

    #[Override]
    public function schemaOwner(): ?string
    {
        $this->failQuery();

        return $this->schemaOwner;
    }

    #[Override]
    public function identityRole(): PostgresRole
    {
        $this->failQuery();

        return new PostgresRole($this->identityLogin, $this->superuser, $this->bypassRowSecurity, $this->createRole, $this->memberships);
    }

    #[Override]
    public function appPrivileges(): array
    {
        $this->failQuery();

        return $this->appPrivileges;
    }

    private function failQuery(): void
    {
        if ($this->queryFailure instanceof ProbeFailed) {
            throw $this->queryFailure;
        }
    }
}
