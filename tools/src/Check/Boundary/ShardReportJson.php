<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Boundary;

use Cbox\Cms\Tooling\Check\Domain\ShardReport;
use InvalidArgumentException;
use JsonException;

/**
 * The JSON form of a ShardReport, which a shard job of the sharded suites writes
 * (`composer check -- --pr --shard=<i>/<n> --shard-report=<file>`) and the verdict job reads
 * (`composer shards:verdict`): `{"format": 1, "index", "count", "passed", "steps": [...]}`, where
 * the steps are the sharded steps the shard ran.
 */
final readonly class ShardReportJson
{
    public const int FORMAT = 1;

    /** The name of a shard's report file; the verdict reads every file of this name. */
    public const string FILE_NAME = 'suite-shard.json';

    public static function encode(ShardReport $report): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'index' => $report->index,
            'count' => $report->count,
            'passed' => $report->passed,
            'steps' => $report->steps,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }

    /**
     * @throws InvalidArgumentException when the text is not a report in the format
     */
    public static function decode(string $json): ShardReport
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The shard report is not JSON: '.$exception->getMessage(), 0, $exception);
        }

        $index = is_array($data) ? ($data['index'] ?? null) : null;
        $count = is_array($data) ? ($data['count'] ?? null) : null;
        $passed = is_array($data) ? ($data['passed'] ?? null) : null;
        $steps = is_array($data) ? ($data['steps'] ?? null) : null;

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_int($index) || ! is_int($count)
            || ! is_bool($passed) || ! is_array($steps) || ! array_is_list($steps)) {
            throw new InvalidArgumentException('The shard report is not in format '.self::FORMAT.'.');
        }

        $names = [];

        foreach ($steps as $step) {
            if (! is_string($step)) {
                throw new InvalidArgumentException('A step of the shard report is a name.');
            }

            $names[] = $step;
        }

        return new ShardReport($index, $count, $passed, $names);
    }
}
