<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Boundary;

use Cbox\Cms\Tooling\Mutation\Domain\ClassTally;
use Cbox\Cms\Tooling\Mutation\Domain\MutationCount;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShardReport;
use InvalidArgumentException;
use JsonException;

/**
 * The JSON form of a MutationShardReport, which a shard job writes and the verdict job reads:
 * `{"format": 2, "index", "count", "paths": [...], "passed", "classes": [{"path", "name",
 * "mutations", "caught", "equivalent"}]}`, where a class's mutations and those caught leave out
 * the equivalent ones it lists the number of (EquivalentMutations).
 */
final readonly class MutationShardReportJson
{
    public const int FORMAT = 2;

    /** The name of a shard's report file; the verdict reads every file of this name. */
    public const string FILE_NAME = 'mutation-shard.json';

    public static function encode(MutationShardReport $report): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'index' => $report->index,
            'count' => $report->count,
            'paths' => $report->paths,
            'passed' => $report->passed,
            'classes' => array_map(static fn (ClassTally $class): array => [
                'path' => $class->path,
                'name' => $class->name,
                'mutations' => $class->count->mutations,
                'caught' => $class->count->caught,
                'equivalent' => $class->count->equivalent,
            ], $report->classes),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }

    /**
     * @throws InvalidArgumentException when the text is not a report in the format
     */
    public static function decode(string $json): MutationShardReport
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The shard report is not JSON: '.$exception->getMessage(), 0, $exception);
        }

        $index = is_array($data) ? ($data['index'] ?? null) : null;
        $count = is_array($data) ? ($data['count'] ?? null) : null;
        $paths = is_array($data) ? ($data['paths'] ?? null) : null;
        $passed = is_array($data) ? ($data['passed'] ?? null) : null;
        $classes = is_array($data) ? ($data['classes'] ?? null) : null;

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_int($index) || ! is_int($count) || ! is_bool($passed)
            || ! is_array($paths) || ! array_is_list($paths) || ! is_array($classes) || ! array_is_list($classes)) {
            throw new InvalidArgumentException('The shard report is not in format '.self::FORMAT.'.');
        }

        $pathList = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                throw new InvalidArgumentException('A source of the shard report is a path.');
            }

            $pathList[] = $path;
        }

        return new MutationShardReport($index, $count, $pathList, $passed, array_map(self::tally(...), $classes));
    }

    private static function tally(mixed $class): ClassTally
    {
        $path = is_array($class) ? ($class['path'] ?? null) : null;
        $name = is_array($class) ? ($class['name'] ?? null) : null;
        $mutations = is_array($class) ? ($class['mutations'] ?? null) : null;
        $caught = is_array($class) ? ($class['caught'] ?? null) : null;
        $equivalent = is_array($class) ? ($class['equivalent'] ?? null) : null;

        if (! is_string($path) || ! is_string($name) || ! is_int($mutations) || ! is_int($caught) || ! is_int($equivalent)) {
            throw new InvalidArgumentException('A class of the shard report has a path, a name, its mutations, those caught and the equivalent ones left out.');
        }

        return new ClassTally($path, $name, new MutationCount($mutations, $caught, $equivalent));
    }
}
