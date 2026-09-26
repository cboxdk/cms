<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;

/**
 * The doctor's own Postgres connection: the app role's connection settings with a short connect
 * timeout, opened on first use and shared by the Postgres probes of one run.
 *
 * A connection of its own, so the doctor never runs inside a transaction of the application and
 * a server that does not answer costs connect_timeout_seconds, not PDO's default of 30 seconds.
 * pdo_pgsql passes PDO::ATTR_TIMEOUT to libpq as connect_timeout and ignores one in the DSN.
 */
#[Internal]
final class DoctorConnection
{
    public const string NAME = 'cms_doctor';

    private ?Connection $connection = null;

    public function __construct(
        private readonly DatabaseManager $databases,
        private readonly Repository $config,
        private readonly DoctorSettings $settings,
    ) {}

    /**
     * Where the connection goes, without the password: "user@host:port/database".
     */
    public function target(): string
    {
        $config = $this->config->get('database.connections.'.$this->settings->connection);

        if (! is_array($config)) {
            return sprintf('the connection %s, which is not configured', $this->settings->connection);
        }

        return sprintf(
            '%s@%s:%s/%s (connection %s)',
            $this->text($config['username'] ?? null),
            $this->text($config['host'] ?? null),
            $this->text($config['port'] ?? null),
            $this->text($config['database'] ?? null),
            $this->settings->connection,
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

        $config = $this->config->get('database.connections.'.$this->settings->connection);

        if (! is_array($config) || ($config['driver'] ?? null) !== 'pgsql') {
            throw ProbeFailed::violation(sprintf(
                'The database connection %s is not configured as a Postgres connection (driver pgsql) in config/database.php.',
                $this->settings->connection,
            ));
        }

        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $options[PDO::ATTR_TIMEOUT] = $this->settings->connectTimeoutSeconds;
        $config['options'] = $options;

        // Registered under its own name, so the reconnect Laravel tries after "Connection refused"
        // finds the same settings instead of failing with "not configured".
        $this->config->set('database.connections.'.self::NAME, $config);
        $this->databases->purge(self::NAME);

        return $this->connection = $this->databases->connection(self::NAME);
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : '?';
    }
}
