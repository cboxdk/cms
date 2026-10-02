<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapSettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads cbox-cms.access into BootstrapSettings: bootstrap_role, the handle of the bootstrap role,
 * and whether the environment the application runs in is production.
 */
#[Internal]
final readonly class BootstrapConfig
{
    public const string CONFIG_KEY = 'cbox-cms.access';

    /** The environment in which the bootstrap is refused (PRD 5.10). */
    public const string PRODUCTION = 'production';

    /**
     * @throws InvalidArgumentException when bootstrap_role is not a role handle
     */
    public static function read(Repository $config, string $environment): BootstrapSettings
    {
        $value = $config->get(self::CONFIG_KEY.'.bootstrap_role');

        try {
            $role = new RoleHandle(is_string($value) ? $value : '');
        } catch (InvalidIdentity $invalid) {
            throw new InvalidArgumentException(sprintf('The setting %s.bootstrap_role must be a role handle. %s', self::CONFIG_KEY, $invalid->getMessage()), 0, $invalid);
        }

        return new BootstrapSettings($role, $environment === self::PRODUCTION);
    }
}
