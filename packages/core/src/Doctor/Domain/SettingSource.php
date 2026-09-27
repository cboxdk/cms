<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Where a session's value of a setting came from: pg_settings.source, a fixed set in Postgres
 * (guc.c, GucSource_Names). The value is the text pg_settings shows.
 */
#[Internal]
enum SettingSource: string
{
    /** The built-in default. */
    case Default = 'default';

    /** An environment variable of the postmaster, such as PGPORT. */
    case EnvironmentVariable = 'environment variable';

    /** postgresql.conf or a file it includes, or postgresql.auto.conf from ALTER SYSTEM. */
    case ConfigurationFile = 'configuration file';

    /** An option on the postmaster's command line. */
    case CommandLine = 'command line';

    /** ALTER ROLE ALL ... SET, for every role in every database. */
    case Global = 'global';

    /** ALTER DATABASE ... SET. */
    case Database = 'database';

    /** ALTER ROLE ... SET. */
    case User = 'user';

    /** ALTER ROLE ... IN DATABASE ... SET. */
    case DatabaseUser = 'database user';

    /** An option the client sent when it connected, such as options=-c... in the DSN. */
    case Client = 'client';

    /** A value the server forces over any other source. */
    case Override = 'override';

    /** The line Postgres draws for error reporting between the sources above and below it. */
    case Interactive = 'interactive';

    /** A value Postgres tests for ALTER DATABASE or ALTER ROLE before it stores it. */
    case Test = 'test';

    /** SET or set_config() in the session. */
    case Session = 'session';

    /** Whether a role setting gave the value: ALTER ROLE ... SET, with or without IN DATABASE. */
    public function isRole(): bool
    {
        return match ($this) {
            self::User, self::DatabaseUser => true,
            self::Default, self::EnvironmentVariable, self::ConfigurationFile, self::CommandLine,
            self::Global, self::Database, self::Client, self::Override, self::Interactive,
            self::Test, self::Session => false,
        };
    }

    /**
     * Whether the value is the server's own, which no role, database or session setting gave: the
     * built-in default, the environment, the configuration file or the command line.
     */
    public function isServer(): bool
    {
        return match ($this) {
            self::Default, self::EnvironmentVariable, self::ConfigurationFile, self::CommandLine => true,
            self::Global, self::Database, self::User, self::DatabaseUser, self::Client,
            self::Override, self::Interactive, self::Test, self::Session => false,
        };
    }
}
