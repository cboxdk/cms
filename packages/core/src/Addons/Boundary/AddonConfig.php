<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Addons\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Core\Addons\Domain\Dto\ServiceActors;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the installation's settings for its addons from `cbox-cms.addons`:
 *
 *     'addons' => [
 *         'service_actors' => [
 *             'acme' => '0199a1b2-...',   // addon namespace => the id of its service actor
 *         ],
 *     ],
 */
#[Internal]
final readonly class AddonConfig
{
    public const string CONFIG_KEY = 'cbox-cms.addons';

    /**
     * @throws InvalidArgumentException when service_actors is not a map from addon namespaces to UUIDv7 actor ids
     */
    public static function read(Repository $config): ServiceActors
    {
        $key = self::CONFIG_KEY.'.service_actors';
        $value = $config->get($key, []);

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf('The setting %s must be a map from addon namespaces to actor ids; it is %s.', $key, get_debug_type($value)));
        }

        $actors = [];

        foreach ($value as $namespace => $id) {
            try {
                $addon = new AddonNamespace((string) $namespace);
            } catch (InvalidAddonManifest|ReservedAddonNamespace $invalid) {
                throw new InvalidArgumentException(sprintf('The setting %s names "%s", which is not an addon namespace. %s', $key, $namespace, $invalid->getMessage()), 0, $invalid);
            }

            if (! is_string($id)) {
                throw new InvalidArgumentException(sprintf('The setting %s.%s must be an actor id; it is %s.', $key, $addon->value, get_debug_type($id)));
            }

            try {
                $actors[$addon->value] = ActorId::fromString($id);
            } catch (InvalidUuid7 $invalid) {
                throw new InvalidArgumentException(sprintf('The setting %s.%s is not an actor id. %s', $key, $addon->value, $invalid->getMessage()), 0, $invalid);
            }
        }

        return new ServiceActors($actors);
    }
}
