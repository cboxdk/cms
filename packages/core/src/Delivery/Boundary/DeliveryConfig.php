<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliverySettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the delivery API's settings from `cbox-cms.delivery` (PRD 8.10, 8.12):
 *
 *     'delivery' => [
 *         'max_age_seconds' => 300,                 // a fragment's and the edge's lifetime, 1 to 86400
 *         'stale_while_revalidate_seconds' => 30,   // 0 to 86400
 *         'stale_if_error_seconds' => 3600,         // 0 to 3600
 *     ],
 */
#[Internal]
final readonly class DeliveryConfig
{
    public const string CONFIG_KEY = 'cbox-cms.delivery';

    /**
     * @throws InvalidArgumentException when a setting is not a whole number or outside its range
     */
    public static function read(Repository $config): DeliverySettings
    {
        $defaults = new DeliverySettings;

        return new DeliverySettings(
            self::seconds($config, 'max_age_seconds', $defaults->maxAge),
            self::seconds($config, 'stale_while_revalidate_seconds', $defaults->staleWhileRevalidate),
            self::seconds($config, 'stale_if_error_seconds', $defaults->staleIfError),
        );
    }

    private static function seconds(Repository $config, string $name, int $default): int
    {
        $value = $config->get(self::CONFIG_KEY.'.'.$name, $default);

        return is_int($value) ? $value : throw new InvalidArgumentException(sprintf(
            'The setting %s.%s must be a whole number; it is %s.',
            self::CONFIG_KEY,
            $name,
            get_debug_type($value),
        ));
    }
}
