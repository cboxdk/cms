<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;

/**
 * cms:install cannot write the genesis, because the process has no owner connection or the
 * connection named is not the owner role's (PRD 4.2). Only the owner role may create the operator.
 */
#[Internal]
final class InstallRefused extends RuntimeException
{
    public const string CODE = 'install_owner_connection_required';

    public static function ownerConnectionMissing(): self
    {
        return new self(sprintf(
            '[%s] The installation operator is created as the owner role, and this process has no owner connection. Run cms:install in the maintenance process, with [cbox-cms.database.owner_connection] naming the owner role\'s connection.',
            self::CODE,
        ));
    }

    public static function notOwner(string $connection): self
    {
        return new self(sprintf(
            '[%s] The connection [%s] may not create the installation operator, so it is not the owner role\'s. Point [cbox-cms.database.owner_connection] at the owner role\'s connection and run the migrations as that role.',
            self::CODE,
            $connection,
        ));
    }
}
