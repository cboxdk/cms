<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedSettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads cbox-cms.seeding into SeedSettings: service_actor, the UUIDv7 of the service actor the
 * seeder writes as, or null.
 */
#[Internal]
final readonly class SeedingConfig
{
    public const string CONFIG_KEY = 'cbox-cms.seeding';

    /**
     * @throws InvalidArgumentException when service_actor is neither null nor a UUIDv7
     */
    public static function read(Repository $config): SeedSettings
    {
        $value = $config->get(self::CONFIG_KEY.'.service_actor');

        if ($value === null || $value === '') {
            return new SeedSettings(null);
        }

        try {
            return new SeedSettings(is_string($value) ? ActorId::fromString($value) : throw new InvalidArgumentException('not text'));
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf('The setting %s.service_actor must be the UUIDv7 of a service actor, or null.', self::CONFIG_KEY));
        }
    }
}
