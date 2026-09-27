<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;

/**
 * Partition maintenance is DDL, and only the owner role runs DDL (PRD 4.2: the app role owns no
 * tables and has no DDL). The manager refuses to run on a connection that is not the owner's.
 */
#[Experimental]
final class OwnerConnectionRequired extends LogicException
{
    public const string CODE = 'partition_owner_required';

    public static function appConnection(string $connection): self
    {
        return new self(sprintf(
            '[%s] Partition maintenance is set to run on the connection [%s], which is the application\'s default connection. It runs DDL, and only the owner role may. Point [cbox-cms.database.owner_connection] at the owner role\'s connection.',
            self::CODE,
            $connection,
        ));
    }

    public static function withoutDdl(string $connection, string $role): self
    {
        return new self(sprintf(
            '[%s] The connection [%s] logs in as the role "%s", which may not create tables in its schema, so it is not the owner role. Point [cbox-cms.database.owner_connection] at the owner role\'s connection.',
            self::CODE,
            $connection,
            $role,
        ));
    }
}
