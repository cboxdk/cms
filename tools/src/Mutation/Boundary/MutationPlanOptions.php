<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Boundary;

use InvalidArgumentException;

/**
 * The options of `composer mutation:plan`:
 *   --output=<file>         write the plan as JSON (MutationPlanJson)
 *   --github-output=<file>  append `count=<n>` and `shards=<JSON list>` to the file, the step
 *                           outputs of GitHub Actions ($GITHUB_OUTPUT), for the shard jobs' matrix
 */
final readonly class MutationPlanOptions
{
    public const string USAGE = 'Usage: composer mutation:plan [-- [--output=<file>] [--github-output=<file>]]';

    private function __construct(
        public ?string $output,
        public ?string $githubOutput,
    ) {}

    /**
     * @param  list<string>  $arguments
     */
    public static function parse(array $arguments): self
    {
        $output = null;
        $githubOutput = null;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--output=') && strlen($argument) > strlen('--output=')) {
                $output = substr($argument, strlen('--output='));
            } elseif (str_starts_with($argument, '--github-output=') && strlen($argument) > strlen('--github-output=')) {
                $githubOutput = substr($argument, strlen('--github-output='));
            } else {
                throw new InvalidArgumentException("Unknown option {$argument}. ".self::USAGE);
            }
        }

        return new self($output, $githubOutput);
    }
}
