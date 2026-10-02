<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\CredentialStore\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads the settings of the identity module from `cbox-cms.identity`.
 */
#[Internal]
final readonly class IdentityConfig
{
    public const string KEY = 'cbox-cms.identity';

    /**
     * The name of the identity role's database connection, `cbox-cms.identity.connection`, or null
     * when the setting is not a non-empty string.
     */
    public static function connection(Repository $config): ?string
    {
        $name = $config->get(self::KEY.'.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
