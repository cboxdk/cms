<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Boundary;

use Cbox\Cms\Tooling\Mutation\Domain\JobResult;
use InvalidArgumentException;

/**
 * The options of `composer mutation:verdict`, all required:
 *   --plan=<file>       the plan the shards ran (`composer mutation:plan -- --output=<file>`)
 *   --reports=<dir>     the directory below which every file named mutation-shard.json is a shard's report
 *   --gates=<result>    how the gates ended: success, failure, cancelled or skipped
 *   --shards=<result>   how the shard jobs ended together, in the same words
 */
final readonly class MutationVerdictOptions
{
    public const string USAGE = 'Usage: composer mutation:verdict -- --plan=<file> --reports=<dir> --gates=<result> --shards=<result>';

    private function __construct(
        public string $plan,
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
            if (preg_match('/^--(plan|reports|gates|shards)=(.+)$/', $argument, $match) !== 1 || isset($values[$match[1]])) {
                throw new InvalidArgumentException("Unknown or repeated option {$argument}. ".self::USAGE);
            }

            $values[$match[1]] = $match[2];
        }

        foreach (['plan', 'reports', 'gates', 'shards'] as $name) {
            if (! isset($values[$name])) {
                throw new InvalidArgumentException("--{$name} is missing. ".self::USAGE);
            }
        }

        return new self($values['plan'], $values['reports'], self::result('gates', $values['gates']), self::result('shards', $values['shards']));
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
