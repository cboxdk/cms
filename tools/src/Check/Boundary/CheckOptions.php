<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Boundary;

use Cbox\Cms\Tooling\Check\Domain\Profile;
use Cbox\Cms\Tooling\Check\Domain\PrPart;
use InvalidArgumentException;

/**
 * The options of `composer check`:
 *   --report=<file>  also write the report as JSON to the file
 *   --brief          leave the output of failed steps out of the console; the report keeps it
 *   --pr             the PR profile as CI runs it (bin/ci) instead of the local profile. Not
 *                    --profile, which is Composer's own option for timing and memory
 *                    By default it runs without mutation testing, which is deferred until after
 *                    v1 (Sylvester, 2 October 2026), and reports its steps as not run
 *   --mutation       with --pr: opt in to mutation testing, the Mutation suite and mutation on
 *                    changed files, as CI's run by hand with the input mutation does
 *   --only=gates     with --pr --mutation: the gates and the Mutation suite without mutation on
 *                    changed files, as CI's gates job of such a run
 *   --shard=<i>/<n>  with --pr --mutation: only mutation on changed files, shard i of the n
 *                    shards of the plan (MutationShards), as CI's shard job i
 *   --mutation-report=<file>  with --shard: also write the shard's report for the verdict
 *   --gate=<n>       run only gate n of the profile, and report the other gates as not run
 *                    (GateSelection); repeat it for more than one gate, such as
 *                    `--pr --gate=7` for the component kit's Storybook alone
 */
final readonly class CheckOptions
{
    public const string USAGE = 'Usage: composer check [-- [--report=<file>] [--brief] [--gate=<n>]... [--pr [--mutation [--only=gates | --shard=<i>/<n> [--mutation-report=<file>]]]]]';

    /**
     * @param  list<int>  $gates  the gates to run, from --gate; empty for every gate
     */
    private function __construct(
        public ?string $reportFile,
        public bool $brief,
        public Profile $profile,
        public PrPart $part,
        public ?string $mutationReportFile,
        public array $gates = [],
    ) {}

    /**
     * @param  list<string>  $arguments  the arguments after the script name
     */
    public static function parse(array $arguments): self
    {
        $reportFile = null;
        $mutationReportFile = null;
        $brief = false;
        $profile = Profile::Local;
        $mutation = false;
        $part = PrPart::all();
        $parts = 0;
        $gates = [];

        foreach ($arguments as $argument) {
            if ($argument === '--brief') {
                $brief = true;
            } elseif (str_starts_with($argument, '--report=') && strlen($argument) > strlen('--report=')) {
                $reportFile = substr($argument, strlen('--report='));
            } elseif (str_starts_with($argument, '--mutation-report=') && strlen($argument) > strlen('--mutation-report=')) {
                $mutationReportFile = substr($argument, strlen('--mutation-report='));
            } elseif ($argument === '--pr') {
                $profile = Profile::Pr;
            } elseif ($argument === '--mutation') {
                $mutation = true;
            } elseif (preg_match('#^--gate=([1-9][0-9]?)$#', $argument, $gate) === 1) {
                $gates[] = (int) $gate[1];
            } elseif ($argument === '--only=gates') {
                $part = PrPart::gates();
                $parts++;
            } elseif (preg_match('#^--shard=([1-9][0-9]{0,3})/([1-9][0-9]{0,3})$#', $argument, $shard) === 1) {
                $part = PrPart::shard((int) $shard[1], (int) $shard[2]);
                $parts++;
            } else {
                throw new InvalidArgumentException("Unknown option {$argument}. ".self::USAGE);
            }
        }

        if ($parts > 1) {
            throw new InvalidArgumentException('Give --only=gates or one --shard, not both or twice. '.self::USAGE);
        }

        if ($parts === 1 && $profile !== Profile::Pr) {
            throw new InvalidArgumentException('--only and --shard pick a part of the PR profile, so they need --pr. '.self::USAGE);
        }

        if ($mutation && $profile !== Profile::Pr) {
            throw new InvalidArgumentException('--mutation opts in to the mutation testing of the PR profile, so it needs --pr. '.self::USAGE);
        }

        if ($parts === 1 && ! $mutation) {
            throw new InvalidArgumentException('--only and --shard pick a part of the run with mutation testing, which is deferred until after v1 (Sylvester, 2 October 2026), so they need --mutation. '.self::USAGE);
        }

        if (! $mutation) {
            $part = PrPart::withoutMutation();
        }

        if ($mutationReportFile !== null && ! $part->isShard()) {
            throw new InvalidArgumentException('--mutation-report writes the report of a shard, so it needs --shard. '.self::USAGE);
        }

        return new self($reportFile, $brief, $profile, $part, $mutationReportFile, array_values(array_unique($gates)));
    }
}
