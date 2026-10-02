<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;
use Cbox\Cms\Tooling\Mutation\Domain\MutationTally;

/**
 * The PR profile of GUARDRAILS 10 as CI runs it today, through `bin/ci`: the steps of gates 1 to
 * 6 from the local profile, unchanged, with gate 5 adding the Mutation suite and mutation on
 * changed files (MutationSteps), gate 8 (the Browser suite), gate 9 (composer audit and npm
 * audit) and gate 10 (`composer docs:check`, documentation with running examples for every
 * public extension point), and gates 7 and 11 reported as not run, each with the reason.
 * GUARDRAILS 10 wants every gate that did not run reported explicitly; a gate that starts running
 * in CI moves out of NOT_RUN. The local profile leaves gate 10 out, as GUARDRAILS 10 says, but
 * gate 5 runs the same audit on the repository in tests/Feature/Tooling/Docs/RepositoryDocsTest.php,
 * so `composer check` fails on every finding of gate 10 as well (GUARDRAILS 7.3).
 *
 * CI runs it in parts (PrPart): the gates job runs every gate but mutation on changed files, which
 * it reports as not run, and each shard job runs only its shard of mutation on changed files and
 * reports the other gates as not run; the verdict job judges them together.
 */
final readonly class PrProfile
{
    /**
     * The gates of the PR profile that CI does not run yet, by number, with the reason.
     *
     * @var array<int, string>
     */
    public const array NOT_RUN = [
        7 => 'not run in CI yet: there is no panel UI or Storybook before the panel skeleton (B1)',
        11 => 'not a command: review by someone other than the author needs branch protection on main that requires it, a repository setting on github.com/cboxdk/cms that Sylvester makes',
    ];

    /**
     * The Pest suite of the tests that need a coverage driver, one of LocalProfile::OTHER_SUITES.
     * It runs in gate 5 before mutation on changed files, which it tests. It runs in one process:
     * each of its few tests starts a Pest run with `--mutate --parallel` of its own, which already
     * uses every CPU.
     */
    public const string MUTATION_SUITE = 'Mutation';

    /**
     * The Pest suite of gate 8, one of LocalProfile::OTHER_SUITES.
     */
    public const string BROWSER_SUITE = 'Browser';

    /**
     * Why the gates job reports mutation on changed files as not run: its shards run it.
     */
    public const string MUTATION_IN_SHARDS = 'run in the shard jobs of mutation on changed files, which the verdict judges together (MutationVerdict)';

    /**
     * Why a shard job reports every gate but mutation on changed files as not run.
     */
    public const string GATE_IN_GATES_JOB = 'run in the gates job; a shard runs only its part of mutation on changed files';

    /**
     * @param  string  $php  the PHP binary
     * @param  list<string>  $composer  the command that runs Composer
     * @param  MutationScope  $mutation  what changed since the base of the change, for mutation on
     *                                   changed files: the whole change, or one shard's part of it
     * @param  PrPart|null  $part  the part of the profile to run; null for all of it
     * @param  MutationTally|null  $tally  where mutation on changed files counts each changed class
     * @return list<Gate>
     */
    public static function gates(string $php, array $composer, MutationScope $mutation, ?PrPart $part = null, ?MutationTally $tally = null): array
    {
        $part ??= PrPart::all();
        $gates = [];

        foreach (LocalProfile::gates($php, $composer) as $gate) {
            $full = match (true) {
                isset(self::NOT_RUN[$gate->number]) => new Gate($gate->number, $gate->title, [Step::notRun($gate->title, self::NOT_RUN[$gate->number])]),
                $gate->number === 5 => self::pest($gate, $php, $mutation, $part, $tally),
                $gate->number === 8 => self::browser($gate, $php),
                $gate->number === 9 => self::audit($gate, $composer),
                $gate->number === 10 => self::docs($gate, $composer),
                default => $gate,
            };
            $gates[] = $part->runsGates() || $full->number === 5 || isset(self::NOT_RUN[$full->number])
                ? $full
                : new Gate($full->number, $full->title, [Step::notRun($full->title, self::GATE_IN_GATES_JOB)]);
        }

        return $gates;
    }

    /**
     * Gate 5: the local profile's steps and the Mutation suite, unless the part is a shard, and
     * mutation on changed files, unless the part is the gates job, which reports it as not run.
     */
    private static function pest(Gate $gate, string $php, MutationScope $mutation, PrPart $part, ?MutationTally $tally): Gate
    {
        $steps = $part->runsGates() ? [...$gate->steps, LocalProfile::suiteStep($php, self::MUTATION_SUITE, parallel: false)] : [];

        return new Gate(5, $gate->title, [
            ...$steps,
            ...($part->runsMutation() ? MutationSteps::for($mutation, $php, $tally) : [Step::notRun(MutationSteps::NAME, self::MUTATION_IN_SHARDS)]),
        ]);
    }

    /**
     * Gate 8: the Browser suite, with skipped and incomplete tests failing it as in gate 5. The
     * browser plugin starts `playwright run-server`, which outlives a Pest process that dies of a
     * fatal error and keeps its output open, so the step runs in a process group of its own that
     * the runner kills when the step ends.
     */
    private static function browser(Gate $gate, string $php): Gate
    {
        return new Gate($gate->number, $gate->title, [
            Step::run(
                self::BROWSER_SUITE,
                [$php, 'vendor/bin/pest', '--testsuite='.self::BROWSER_SUITE, ...LocalProfile::FAIL_FLAGS],
                ownProcessGroup: true,
            ),
        ]);
    }

    /**
     * Gate 9 (PRD 13.8, GUARDRAILS 6): composer audit of the lock file and npm audit. Any
     * security advisory fails the gate. Abandoned packages are reported, not failed; the JSON
     * report of composer audit lets the step list them as notes.
     *
     * @param  list<string>  $composer
     */
    private static function audit(Gate $gate, array $composer): Gate
    {
        return new Gate($gate->number, $gate->title, [
            Step::run(
                'composer audit',
                [...$composer, 'audit', '--locked', '--abandoned=report', '--format=json'],
                reader: new ComposerAuditReader,
            ),
            Step::run('npm audit', ['npm', 'audit']),
        ]);
    }

    /**
     * Gate 10 (GUARDRAILS 2.4, PRD 14.4): `composer docs:check`, which fails on an extension point
     * without a page and a running example, and on a page whose embedded code is not the tested
     * file byte for byte.
     *
     * @param  list<string>  $composer
     */
    private static function docs(Gate $gate, array $composer): Gate
    {
        return new Gate($gate->number, $gate->title, [
            Step::run('docs:check', [...$composer, 'docs:check']),
        ]);
    }
}
