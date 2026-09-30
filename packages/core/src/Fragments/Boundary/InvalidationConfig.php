<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Fragments\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Fragments\Domain\Dto\InvalidationSettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the invalidation subscriber's settings from `cbox-cms.fragments` (PRD 8.12 point 1):
 *
 *     'fragments' => [
 *         'fence_seconds' => 60,   // how long a purge fence lives, 1 to 86400
 *     ],
 */
#[Internal]
final readonly class InvalidationConfig
{
    public const string CONFIG_KEY = 'cbox-cms.fragments';

    /**
     * @throws InvalidArgumentException when a setting is not a whole number or outside its range
     */
    public static function read(Repository $config): InvalidationSettings
    {
        $fence = $config->get(self::CONFIG_KEY.'.fence_seconds', InvalidationSettings::DEFAULT_FENCE_SECONDS);

        return new InvalidationSettings(is_int($fence) ? $fence : throw new InvalidArgumentException(sprintf(
            'The setting %s.fence_seconds must be a whole number; it is %s.',
            self::CONFIG_KEY,
            get_debug_type($fence),
        )));
    }
}
