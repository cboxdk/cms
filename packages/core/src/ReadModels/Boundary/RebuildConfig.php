<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildSettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads cbox-cms.rebuild into RebuildSettings: service_actor, the UUIDv7 of the service actor a
 * rebuild runs as, or null; and chunk_size, the entries of one chunk, a whole number from 1 to
 * RebuildSettings::MAX_CHUNK_SIZE, 100 when it is not set.
 */
#[Internal]
final readonly class RebuildConfig
{
    public const string CONFIG_KEY = 'cbox-cms.rebuild';

    /**
     * @throws InvalidArgumentException when a setting is not of its type or outside its range
     */
    public static function read(Repository $config): RebuildSettings
    {
        $chunkSize = $config->get(self::CONFIG_KEY.'.chunk_size', 100);

        if (! is_int($chunkSize) || $chunkSize < 1 || $chunkSize > RebuildSettings::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException(sprintf('The setting %s.chunk_size must be a whole number from 1 to %d.', self::CONFIG_KEY, RebuildSettings::MAX_CHUNK_SIZE));
        }

        return new RebuildSettings(self::serviceActor($config->get(self::CONFIG_KEY.'.service_actor')), $chunkSize);
    }

    private static function serviceActor(mixed $value): ?ActorId
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return is_string($value) ? ActorId::fromString($value) : throw new InvalidArgumentException('not text');
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf('The setting %s.service_actor must be the UUIDv7 of a service actor, or null.', self::CONFIG_KEY));
        }
    }
}
