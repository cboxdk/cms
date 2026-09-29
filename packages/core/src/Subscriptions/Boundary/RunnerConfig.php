<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the event runner's settings from `cbox-cms.events.runner` (PRD 7.4 to 7.8):
 *
 *     'events' => [
 *         'runner' => [
 *             'service_actor' => null,     // the id of the service actor the subscribers run as
 *             'batch_size' => 100,         // events per batch, 1 to 10000
 *             'batch_budget_ms' => 1000,   // 1 to 1900, so a batch's transaction stays under 2 s
 *             'max_attempts' => 5,         // tries of an event before its aggregate is parked
 *             'backoff_base_ms' => 100,    // the wait after the first failed try, doubled per try
 *             'backoff_max_ms' => 5000,    // the longest wait between tries
 *             'idle_sleep_ms' => 200,      // the wait when the lane had nothing to do
 *         ],
 *     ],
 */
#[Internal]
final readonly class RunnerConfig
{
    public const string CONFIG_KEY = 'cbox-cms.events.runner';

    private const array INTEGERS = [
        'batch_size' => 100,
        'batch_budget_ms' => 1_000,
        'max_attempts' => 5,
        'backoff_base_ms' => 100,
        'backoff_max_ms' => 5_000,
        'idle_sleep_ms' => 200,
    ];

    /**
     * @throws InvalidArgumentException when a setting is not of its type or outside its range
     */
    public static function read(Repository $config): RunnerSettings
    {
        $values = [];

        foreach (self::INTEGERS as $name => $default) {
            $value = $config->get(self::CONFIG_KEY.'.'.$name, $default);

            $values[$name] = is_int($value) ? $value : throw new InvalidArgumentException(sprintf(
                'The setting %s.%s must be a whole number; it is %s.',
                self::CONFIG_KEY,
                $name,
                get_debug_type($value),
            ));
        }

        return new RunnerSettings(
            self::serviceActor($config->get(self::CONFIG_KEY.'.service_actor')),
            $values['batch_size'],
            $values['batch_budget_ms'],
            $values['max_attempts'],
            $values['backoff_base_ms'],
            $values['backoff_max_ms'],
            $values['idle_sleep_ms'],
        );
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
