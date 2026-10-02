<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Egress\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressSettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the egress gateway's timeouts (PRD 7.14) from `cbox-cms.egress`:
 *
 *     'egress' => [
 *         'connect_timeout_ms' => 2000,   // 1 to EgressSettings::MAX_CONNECT_MILLISECONDS
 *         'timeout_ms' => 10000,          // the connect timeout to EgressSettings::MAX_TIMEOUT_MILLISECONDS
 *     ],
 */
#[Internal]
final readonly class EgressConfig
{
    public const string CONFIG_KEY = 'cbox-cms.egress';

    public const int DEFAULT_CONNECT_TIMEOUT_MS = 2000;

    public const int DEFAULT_TIMEOUT_MS = 10_000;

    /**
     * @throws InvalidArgumentException when a timeout is not a whole number of milliseconds in its range
     */
    public static function read(Repository $config): EgressSettings
    {
        $connect = self::milliseconds($config, 'connect_timeout_ms', self::DEFAULT_CONNECT_TIMEOUT_MS);
        $total = self::milliseconds($config, 'timeout_ms', self::DEFAULT_TIMEOUT_MS);

        try {
            return new EgressSettings($connect, $total);
        } catch (InvalidArgumentException $invalid) {
            throw new InvalidArgumentException(sprintf('The setting %s is invalid: %s', self::CONFIG_KEY, $invalid->getMessage()), 0, $invalid);
        }
    }

    private static function milliseconds(Repository $config, string $name, int $default): int
    {
        $value = $config->get(self::CONFIG_KEY.'.'.$name, $default);

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf(
                'The setting %s.%s must be a whole number of milliseconds; it is %s.',
                self::CONFIG_KEY,
                $name,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
