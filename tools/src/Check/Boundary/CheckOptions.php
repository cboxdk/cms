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
 *                    By default it runs every gate, with the sharded suites whole, and without
 *                    mutation testing, which is deferred until after v1 (Sylvester, 2 October
 *                    2026), and reports its steps as not run
 *   --only=gates     with --pr: the gates part, every gate but the suites the plan shards (the
 *                    Postgres suite of gate 5 and the Browser suite of gate 8), which it reports
 *                    as run in the shard jobs; with --pr --mutation: the gates and the Mutation
 *                    suite without mutation on changed files
 *   --shard=<i>/<n>  with --pr: shard i of the n shards of the declared plan of those suites
 *                    (ShardPlan), as CI's shard job i; with --pr --mutation: only mutation on
 *                    changed files, shard i of the n shards of its plan (MutationShards)
 *   --shard-report=<file>  with --shard and without --mutation: also write the shard's report for
 *                          the verdict (composer shards:verdict)
 *   --mutation       with --pr: opt in to mutation testing, the Mutation suite and mutation on
 *                    changed files, as CI's run by hand with the input mutation does. Such a run
 *                    shards mutation on changed files instead of the suites, which stay whole in
 *                    its gates part
 *   --mutation-report=<file>  with --mutation --shard: also write the shard's report for the
 *                             verdict (composer mutation:verdict)
 *   --gate=<n>       run only gate n of the profile, and report the other gates as not run
 *                    (GateSelection); repeat it for more than one gate, such as
 *                    `--pr --gate=7` for the component kit's Storybook alone
 */
final readonly class CheckOptions
{
    public const string USAGE = 'Usage: composer check [-- [--report=<file>] [--brief] [--gate=<n>]... [--pr [--mutation] [--only=gates | --shard=<i>/<n> [--shard-report=<file> | --mutation-report=<file>]]]]';

    /**
     * @param  list<int>  $gates  the gates to run, from --gate; empty for every gate
     */
    private function __construct(
        public ?string $reportFile,
        public bool $brief,
        public Profile $profile,
        public PrPart $part,
        public ?string $mutationReportFile,
        public ?string $shardReportFile = null,
        public array $gates = [],
    ) {}

    /**
     * @param  list<string>  $arguments  the arguments after the script name
     */
    public static function parse(array $arguments): self
    {
        $reportFile = null;
        $mutationReportFile = null;
        $shardReportFile = null;
        $brief = false;
        $profile = Profile::Local;
        $mutation = false;
        $gatesOnly = false;
        $shard = null;
        $shards = null;
        $parts = 0;
        $gates = [];

        foreach ($arguments as $argument) {
            if ($argument === '--brief') {
                $brief = true;
            } elseif (str_starts_with($argument, '--report=') && strlen($argument) > strlen('--report=')) {
                $reportFile = substr($argument, strlen('--report='));
            } elseif (str_starts_with($argument, '--mutation-report=') && strlen($argument) > strlen('--mutation-report=')) {
                $mutationReportFile = substr($argument, strlen('--mutation-report='));
            } elseif (str_starts_with($argument, '--shard-report=') && strlen($argument) > strlen('--shard-report=')) {
                $shardReportFile = substr($argument, strlen('--shard-report='));
            } elseif ($argument === '--pr') {
                $profile = Profile::Pr;
            } elseif ($argument === '--mutation') {
                $mutation = true;
            } elseif (preg_match('#^--gate=([1-9][0-9]?)$#', $argument, $gate) === 1) {
                $gates[] = (int) $gate[1];
            } elseif ($argument === '--only=gates') {
                $gatesOnly = true;
                $parts++;
            } elseif (preg_match('#^--shard=([1-9][0-9]{0,3})/([1-9][0-9]{0,3})$#', $argument, $index) === 1) {
                $shard = (int) $index[1];
                $shards = (int) $index[2];
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

        $part = match (true) {
            $mutation && $gatesOnly => PrPart::gates(),
            $mutation && $shard !== null => PrPart::shard($shard, (int) $shards),
            $mutation => PrPart::all(),
            $gatesOnly => PrPart::suiteGates(),
            $shard !== null => PrPart::suiteShard($shard, (int) $shards),
            default => PrPart::withoutMutation(),
        };

        if ($mutationReportFile !== null && ! $part->isMutationShard()) {
            throw new InvalidArgumentException('--mutation-report writes the report of a shard of mutation on changed files, so it needs --mutation and --shard. '.self::USAGE);
        }

        if ($shardReportFile !== null && ! $part->isSuiteShard()) {
            throw new InvalidArgumentException('--shard-report writes the report of a shard of the sharded suites, so it needs --shard without --mutation. '.self::USAGE);
        }

        return new self($reportFile, $brief, $profile, $part, $mutationReportFile, $shardReportFile, array_values(array_unique($gates)));
    }
}
