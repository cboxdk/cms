<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Dto\DdlPrivileges;
use Cbox\Cms\Core\Doctor\Domain\Dto\InstalledExtensions;
use Cbox\Cms\Core\Doctor\Domain\Dto\OpenTransactions;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresRole;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresVersion;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleMembership;
use Cbox\Cms\Core\Doctor\Domain\Dto\RowSecurity;
use Cbox\Cms\Core\Doctor\Domain\Dto\TimeoutSetting;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;

/**
 * A Postgres that keeps the runtime contract until the test changes a property: version 17.11,
 * the app role cms_app without superuser, BYPASSRLS or CREATEROLE and without privileged memberships,
 * transaction_timeout and idle_in_transaction_session_timeout 5 s from the role, no open transaction
 * that holds an id or a snapshot, no prepared transactions, no DDL, and two tables with row
 * level security that force it, and the extensions ltree and plpgsql installed.
 */
final class FakePostgresProbe implements PostgresProbe
{
    public ?ProbeFailed $connectFailure = null;

    /** Thrown by every query method except connect(). */
    public ?ProbeFailed $queryFailure = null;

    public int $versionNumber = 170_011;

    public string $versionText = '17.11';

    public bool $superuser = false;

    public bool $bypassRowSecurity = false;

    public bool $createRole = false;

    /** @var list<RoleMembership> */
    public array $memberships = [];

    public int $transactionTimeoutMs = 5000;

    public SettingSource $transactionTimeoutSource = SettingSource::User;

    public int $idleInTransactionTimeoutMs = 5000;

    public SettingSource $idleInTransactionTimeoutSource = SettingSource::User;

    public OpenTransactions $openTransactions;

    public int $maxPreparedTransactions = 0;

    /** @var list<string> */
    public array $ownedRelations = [];

    /** @var list<string> The owners of ownedRelations, when there are any. */
    public array $ownerRoles = ['cms_app'];

    public bool $createOnDatabase = false;

    /** @var list<string> */
    public array $schemasWithCreate = [];

    public int $rowSecurityTables = 2;

    /** @var list<string> Tables with row level security that do not force it. */
    public array $unforcedTables = [];

    /** @var list<string> The installed extensions, sorted. */
    public array $extensions = ['ltree', 'plpgsql'];

    public int $connects = 0;

    public function __construct()
    {
        $this->openTransactions = new OpenTransactions(null, null);
    }

    public function target(): string
    {
        return 'cms_app@fake:5432/cms (connection fake)';
    }

    public function connect(): void
    {
        $this->connects++;

        if ($this->connectFailure instanceof ProbeFailed) {
            throw $this->connectFailure;
        }
    }

    public function version(): PostgresVersion
    {
        $this->query();

        return new PostgresVersion($this->versionNumber, $this->versionText);
    }

    public function role(): PostgresRole
    {
        $this->query();

        return new PostgresRole('cms_app', $this->superuser, $this->bypassRowSecurity, $this->createRole, $this->memberships);
    }

    public function transactionTimeout(): TimeoutSetting
    {
        $this->query();

        return new TimeoutSetting('cms_app', $this->transactionTimeoutMs, $this->transactionTimeoutSource);
    }

    public function idleInTransactionTimeout(): TimeoutSetting
    {
        $this->query();

        return new TimeoutSetting('cms_app', $this->idleInTransactionTimeoutMs, $this->idleInTransactionTimeoutSource);
    }

    public function openTransactions(): OpenTransactions
    {
        $this->query();

        return $this->openTransactions;
    }

    public function maxPreparedTransactions(): int
    {
        $this->query();

        return $this->maxPreparedTransactions;
    }

    public function ddlPrivileges(): DdlPrivileges
    {
        $this->query();

        return new DdlPrivileges(
            'cms_app',
            'cms',
            $this->ownedRelations,
            count($this->ownedRelations),
            $this->ownedRelations === [] ? [] : $this->ownerRoles,
            $this->createOnDatabase,
            $this->schemasWithCreate,
        );
    }

    public function rowSecurity(): RowSecurity
    {
        $this->query();

        return new RowSecurity('cms', $this->rowSecurityTables, $this->unforcedTables, count($this->unforcedTables));
    }

    public function extensions(): InstalledExtensions
    {
        $this->query();

        return new InstalledExtensions('cms', $this->extensions);
    }

    private function query(): void
    {
        if ($this->queryFailure instanceof ProbeFailed) {
            throw $this->queryFailure;
        }
    }
}
