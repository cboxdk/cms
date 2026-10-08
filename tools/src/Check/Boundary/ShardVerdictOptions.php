<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Boundary;

use Cbox\Cms\Tooling\Mutation\Domain\JobResult;
use InvalidArgumentException;

/**
 * The options of `composer shards:verdict`, all required:
 *   --reports=<dir>     the directory below which every file named suite-shard.json is a shard's report
 *   --gates=<result>    how the gates part ended: success, failure, cancelled or skipped
 *   --shards=<result>   how the shard jobs ended together, in the same words
 */
final readonly class ShardVerdictOptions
{
    public const string USAGE = 'Usage: composer shards:verdict -- --reports=<dir> --gates=<result> --shards=<result>';

    private function __construct(
        public string $reports,
        public JobResult $gates,
        public JobResult $shards,
    ) {}

    /**
     * @param  list<string>  $arguments
     */
    public static function parse(array $arguments): self
    {
        $values = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--(reports|gates|shards)=(.+)$/', $argument, $match) !== 1 || isset($values[$match[1]])) {
                throw new InvalidArgumentException("Unknown or repeated option {$argument}. ".self::USAGE);
            }

            $values[$match[1]] = $match[2];
        }

        foreach (['reports', 'gates', 'shards'] as $name) {
            if (! isset($values[$name])) {
                throw new InvalidArgumentException("--{$name} is missing. ".self::USAGE);
            }
        }

        return new self($values['reports'], self::result('gates', $values['gates']), self::result('shards', $values['shards']));
    }

    private static function result(string $option, string $value): JobResult
    {
        return JobResult::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            '--%s=%s is not a result; it is one of %s. %s',
            $option,
            $value,
            implode(', ', array_map(static fn (JobResult $result): string => $result->value, JobResult::cases())),
            self::USAGE,
        ));
    }
}
