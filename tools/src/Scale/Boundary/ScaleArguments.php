<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Scale\Boundary;

use Cbox\Cms\Tooling\Scale\Domain\ScaleOptions;
use InvalidArgumentException;

/**
 * Reads the arguments of `composer scale:check -- [--entries=N] [--runs=N] [--profile=name]
 * [--seed=N] [--sections=N] [--database=name] [--keep]`. A number may group its digits with
 * underscores, as in 1_000_000. The database may not be one the checkouts share or test in.
 */
final readonly class ScaleArguments
{
    public const string USAGE = 'Usage: composer scale:check -- [--entries=1000000] [--runs=11] [--profile=scale] [--seed=1] [--sections=40] [--database=cms_scale] [--keep]';

    /**
     * @param  list<string>  $arguments
     * @param  string  $dev  the shared dev database, which the scale check never uses
     * @param  string  $test  the configured test database, whose name and every checkout's test database below it the scale check never uses
     *
     * @throws InvalidArgumentException for an unknown argument or a value out of range
     */
    public static function parse(array $arguments, string $dev, string $test): ScaleOptions
    {
        $values = [];
        $keep = false;

        foreach ($arguments as $argument) {
            if ($argument === '--keep') {
                $keep = true;

                continue;
            }

            if (preg_match('/\A--(entries|runs|profile|seed|sections|database)=(.+)\z/', $argument, $parts) !== 1 || isset($values[$parts[1]])) {
                throw new InvalidArgumentException(sprintf('Unknown or repeated argument "%s".', $argument));
            }

            $values[$parts[1]] = $parts[2];
        }

        $database = $values['database'] ?? ScaleOptions::DATABASE;

        if ($database === $dev || $database === $test || str_starts_with($database, $test.'_')) {
            throw new InvalidArgumentException(sprintf('The scale check seeds a database of its own, never the shared dev database "%s" or a test database "%s"; --database names "%s".', $dev, $test, $database));
        }

        return new ScaleOptions(
            entries: self::number($values, 'entries', ScaleOptions::ENTRIES),
            runs: self::number($values, 'runs', ScaleOptions::RUNS),
            profile: $values['profile'] ?? ScaleOptions::PROFILE,
            seed: self::number($values, 'seed', ScaleOptions::SEED),
            database: $database,
            sections: self::number($values, 'sections', ScaleOptions::SECTIONS),
            keep: $keep,
        );
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function number(array $values, string $name, int $default): int
    {
        if (! isset($values[$name])) {
            return $default;
        }

        $digits = str_replace('_', '', $values[$name]);

        if (preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/', $digits) !== 1) {
            throw new InvalidArgumentException(sprintf('--%s is a whole number, got "%s".', $name, $values[$name]));
        }

        return (int) $digits;
    }
}
