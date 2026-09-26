<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/**
 * A Postgres connection of the doctor: the settings of one of the application's connections
 * with a short connect timeout, registered under a name of its own and opened on first use.
 * The app role's copy, cms_doctor, is shared by the Postgres probes of one run; the owner role's
 * copy, cms_doctor_owner, is only read by postgres.lc_messages.
 *
 * A connection of its own, so the doctor never runs inside a transaction of the application and
 * a server that does not answer costs connect_timeout_seconds, not PDO's default of 30 seconds.
 * pdo_pgsql passes PDO::ATTR_TIMEOUT to libpq as connect_timeout and ignores one in the DSN.
 */
#[Internal]
final class DoctorConnection
{
    /** The copy of the app role's connection. */
    public const string NAME = 'cms_doctor';

    /** The copy of the owner role's connection. */
    public const string OWNER_NAME = 'cms_doctor_owner';

    private ?Connection $connection = null;

    /**
     * @param  string  $source  the application's connection whose settings are copied
     * @param  string  $name  the name the copy is registered under
     */
    public function __construct(
        private readonly DatabaseManager $databases,
        private readonly Repository $config,
        public readonly string $source,
        private readonly string $name,
        private readonly int $connectTimeoutSeconds,
    ) {}

    /**
     * Where the connection goes, without the password: "user@host:port/database".
     */
    public function target(): string
    {
        $config = $this->config->get('database.connections.'.$this->source);

        if (! is_array($config)) {
            return sprintf('the connection %s, which is not configured', $this->source);
        }

        return sprintf(
            '%s@%s:%s/%s (connection %s)',
            $this->text($config['username'] ?? null),
            $this->text($config['host'] ?? null),
            $this->text($config['port'] ?? null),
            $this->text($config['database'] ?? null),
            $this->source,
        );
    }

    /**
     * @throws ProbeFailed violation when the connection is not configured as Postgres
     */
    public function get(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        $config = $this->config->get('database.connections.'.$this->source);

        if (! is_array($config) || ($config['driver'] ?? null) !== 'pgsql') {
            throw ProbeFailed::violation(sprintf(
                'The database connection %s is not configured as a Postgres connection (driver pgsql) in config/database.php.',
                $this->source,
            ));
        }

        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $options[PDO::ATTR_TIMEOUT] = $this->connectTimeoutSeconds;
        $config['options'] = $options;

        // Registered under its own name, so the reconnect Laravel tries after "Connection refused"
        // finds the same settings instead of failing with "not configured".
        $this->config->set('database.connections.'.$this->name, $config);
        $this->databases->purge($this->name);

        return $this->connection = $this->databases->connection($this->name);
    }

    /**
     * Runs a query that only reads and returns its rows.
     *
     * @return list<mixed>
     *
     * @throws ProbeFailed classified by PostgresErrors
     */
    public function rows(string $sql): array
    {
        $connection = $this->get();

        try {
            return array_values($connection->select($sql, [], false));
        } catch (Throwable $thrown) {
            throw PostgresErrors::classify($thrown);
        }
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : '?';
    }
}
