<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Domain\InstallationOperator;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * The installation operator on Postgres: one lookup, `cms_installation_operator()`, which runs as the
 * owner role (SECURITY DEFINER), because the app role reads no row of `installation` itself. It runs
 * on the default connection, or the one named, on the write PDO, inside the caller's transaction
 * when one is open, and needs no actor context.
 */
#[Internal]
final readonly class PostgresInstallationOperator implements InstallationOperator
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function find(): ?ActorId
    {
        $id = $this->connections->connection($this->connection)->scalar(PostgresOperatorGenesis::OPERATOR, [], false);

        if ($id === null) {
            return null;
        }

        if (! is_string($id)) {
            throw new UnexpectedValueException(sprintf('The installation operator is read as text, got %s.', get_debug_type($id)));
        }

        return ActorId::fromString($id);
    }
}
