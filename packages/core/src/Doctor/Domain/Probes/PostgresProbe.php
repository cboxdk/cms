<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\DdlPrivileges;
use Cbox\Cms\Core\Doctor\Domain\Dto\InstalledExtensions;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresRole;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresVersion;
use Cbox\Cms\Core\Doctor\Domain\Dto\RowSecurity;
use Cbox\Cms\Core\Doctor\Domain\Dto\TimeoutSetting;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;

/**
 * Postgres as the application's app role sees it, on its own connection with a short connect
 * timeout (PRD 4.2). Every method throws ProbeFailed when it cannot answer.
 */
#[Internal]
interface PostgresProbe
{
    /**
     * Where the connection goes, without the password, such as "cms_app@127.0.0.1:5432/cms".
     */
    public function target(): string;

    /**
     * Connects and runs a trivial query.
     *
     * @throws ProbeFailed unavailable when the server cannot be reached or is starting or stopping,
     *                     violation when it refuses the login or the database
     */
    public function connect(): void;

    /** @throws ProbeFailed */
    public function version(): PostgresVersion;

    /** @throws ProbeFailed */
    public function role(): PostgresRole;

    /** @throws ProbeFailed */
    public function transactionTimeout(): TimeoutSetting;

    /** @throws ProbeFailed */
    public function maxPreparedTransactions(): int;

    /** @throws ProbeFailed */
    public function ddlPrivileges(): DdlPrivileges;

    /**
     * The row level security of the tables and partitioned tables outside the system schemas.
     *
     * @throws ProbeFailed
     */
    public function rowSecurity(): RowSecurity;

    /**
     * The extensions installed in the current database.
     *
     * @throws ProbeFailed
     */
    public function extensions(): InstalledExtensions;
}
