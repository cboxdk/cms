<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Dto\DdlPrivileges;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresRole;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresVersion;
use Cbox\Cms\Core\Doctor\Domain\Dto\TimeoutSetting;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;

/**
 * A Postgres that keeps the runtime contract until the test changes a property: version 17.11,
 * the app role cms_app without superuser or BYPASSRLS, transaction_timeout 5 s from the role,
 * no prepared transactions and no DDL.
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

    public int $transactionTimeoutMs = 5000;

    public string $transactionTimeoutSource = 'user';

    public int $maxPreparedTransactions = 0;

    /** @var list<string> */
    public array $ownedRelations = [];

    public bool $createOnDatabase = false;

    /** @var list<string> */
    public array $schemasWithCreate = [];

    public int $connects = 0;

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

        return new PostgresRole('cms_app', $this->superuser, $this->bypassRowSecurity);
    }

    public function transactionTimeout(): TimeoutSetting
    {
        $this->query();

        return new TimeoutSetting('cms_app', $this->transactionTimeoutMs, $this->transactionTimeoutSource);
    }

    public function maxPreparedTransactions(): int
    {
        $this->query();

        return $this->maxPreparedTransactions;
    }

    public function ddlPrivileges(): DdlPrivileges
    {
        $this->query();

        return new DdlPrivileges('cms_app', 'cms', $this->ownedRelations, count($this->ownedRelations), $this->createOnDatabase, $this->schemasWithCreate);
    }

    private function query(): void
    {
        if ($this->queryFailure instanceof ProbeFailed) {
            throw $this->queryFailure;
        }
    }
}
