<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the query pipeline's settings from `cbox-cms.queries`:
 *
 *     'queries' => [
 *         'budgets' => [
 *             'anonymous' => 200,   // the cost budget of a read without a credential, from 0
 *             'actor' => 1000,      // the cost budget of a read as an actor, from 0
 *         ],
 *     ],
 */
#[Internal]
final readonly class QueryConfig
{
    public const string CONFIG_KEY = 'cbox-cms.queries';

    public const int DEFAULT_ANONYMOUS_BUDGET = 200;

    public const int DEFAULT_ACTOR_BUDGET = 1000;

    /**
     * @throws InvalidArgumentException when a budget is not a whole number from 0
     */
    public static function read(Repository $config): QuerySettings
    {
        return new QuerySettings(
            self::budget($config, 'anonymous', self::DEFAULT_ANONYMOUS_BUDGET),
            self::budget($config, 'actor', self::DEFAULT_ACTOR_BUDGET),
        );
    }

    private static function budget(Repository $config, string $principal, int $default): QueryCost
    {
        $key = self::CONFIG_KEY.'.budgets.'.$principal;
        $value = $config->get($key, $default);

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException(sprintf(
                'The setting %s must be a whole number from 0; it is %s.',
                $key,
                is_int($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return new QueryCost($value);
    }
}
