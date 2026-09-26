<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One statement of a test database's set-up, as docker/postgres/sql/database.sql has it with its
 * psql variables substituted. A statement that psql ends with `\gexec` is a query whose every
 * value is itself a statement to run, such as `SELECT format('CREATE DATABASE %I ...') WHERE NOT
 * EXISTS (...)`.
 */
#[Experimental]
final readonly class SetupStatement
{
    public function __construct(
        public string $sql,
        public bool $gexec = false,
    ) {}

    /**
     * The statement as psql reads it: ended with `;`, or with `\gexec`.
     */
    public function psql(): string
    {
        return $this->sql.($this->gexec ? ' \gexec' : ';');
    }
}
