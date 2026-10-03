<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;
use Cbox\Cms\Tooling\Mutation\Domain\MutationTally;
use InvalidArgumentException;

/**
 * The PR profile of GUARDRAILS 10 as CI runs it today, through `bin/ci`: the steps of gates 1 to
 * 6 from the local profile, unchanged, gate 7 (the component kit's Storybook: its build, a story
 * for every component the kit exports, and every story as a test with its play function, axe and
 * its visual baseline), gate 8 (`composer panel:build`, then the Browser suite in Chromium and its
 * group browser-matrix in Firefox and WebKit), gate 9 (composer audit and npm audit) and gate 10
 * (`composer docs:check`, documentation with running examples for every public extension point),
 * and gate 11 reported as not run, with the reason.
 *
 * Mutation testing is deferred until after v1 (Sylvester, 2 October 2026), so by default gate 5
 * reports the Mutation suite and mutation on changed files (MutationSteps) as not run, with
 * MUTATION_DEFERRED. With `--mutation` (CMS_CI_MUTATION=1 for bin/ci) gate 5 adds both, as it did
 * before the decision.
 *
 * GUARDRAILS 10 wants every gate that did not run reported explicitly; a gate that starts running
 * in CI moves out of NOT_RUN. The local profile leaves gate 10 out, as GUARDRAILS 10 says, but
 * gate 5 runs the same audit on the repository in tests/Feature/Tooling/Docs/RepositoryDocsTest.php,
 * so `composer check` fails on every finding of gate 10 as well (GUARDRAILS 7.3).
 *
 * With mutation testing, CI runs it in parts (PrPart): the gates job runs every gate but mutation
 * on changed files, which it reports as not run, and each shard job runs only its shard of
 * mutation on changed files and reports the other gates as not run; the verdict job judges them
 * together.
 */
final readonly class PrProfile
{
    /**
     * The gates of the PR profile that CI does not run yet, by number, with the reason.
     *
     * @var array<int, string>
     */
    public const array NOT_RUN = [
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
     * The steps of gate 7, by name, with the npm script each runs (the panel extension
     * architecture of 2 October 2026, section 2.7, and decision D10): the build of the kit's
     * Storybook, the check that every component the kit exports has a story, and the story tests
     * in Chromium, which compare each story with its baseline rendered in the dev image.
     *
     * @var array<string, string>
     */
    public const array STORYBOOK_STEPS = [
        'Storybook build' => 'storybook:build',
        'Story exports' => 'storybook:exports',
        'Story tests' => 'storybook:stories',
    ];

    /**
     * The Pest suite of gate 8, one of LocalProfile::OTHER_SUITES.
     */
    public const string BROWSER_SUITE = 'Browser';

    /**
     * The group of Browser tests gate 8 also runs in Firefox and WebKit: what the panel promises
     * every browser, such as its shared modules and import map under its Content-Security-Policy
     * (PRD 13.4).
     */
    public const string BROWSER_MATRIX_GROUP = 'browser-matrix';

    /**
     * The browsers gate 8 runs the group BROWSER_MATRIX_GROUP in besides Chromium, by the name of
     * their step, with the name the browser plugin's --browser option takes. docker/ci-setup.sh
     * installs them.
     *
     * @var array<string, string>
     */
    public const array MATRIX_BROWSERS = [
        'Browser in Firefox' => 'firefox',
        'Browser in WebKit' => 'safari',
    ];

    /**
     * The Composer script that builds the panel from js/panel into packages/panel/dist in the dev
     * image, which the Browser suite's panel pages load.
     */
    public const string PANEL_BUILD = 'panel:build';

    /**
     * Why the gates job reports mutation on changed files as not run: its shards run it.
     */
    public const string MUTATION_IN_SHARDS = 'run in the shard jobs of mutation on changed files, which the verdict judges together (MutationVerdict)';

    /**
     * Why the default run reports the Mutation suite and mutation on changed files as not run.
     */
    public const string MUTATION_DEFERRED = 'mutation testing deferred until after v1 (Sylvester, 2 October 2026); run it with --mutation, or CMS_CI_MUTATION=1 for bin/ci';

    /**
     * Why a shard job reports every gate but mutation on changed files as not run.
     */
    public const string GATE_IN_GATES_JOB = 'run in the gates job; a shard runs only its part of mutation on changed files';

    /**
     * @param  string  $php  the PHP binary
     * @param  list<string>  $composer  the command that runs Composer
     * @param  MutationScope|null  $mutation  what changed since the base of the change, for
     *                                        mutation on changed files: the whole change, or one
     *                                        shard's part of it; needed only by a part that runs it
     * @param  PrPart|null  $part  the part of the profile to run; null for the default, without
     *                             mutation testing
     * @param  MutationTally|null  $tally  where mutation on changed files counts each changed class
     * @return list<Gate>
     */
    public static function gates(string $php, array $composer, ?MutationScope $mutation = null, ?PrPart $part = null, ?MutationTally $tally = null): array
    {
        $part ??= PrPart::withoutMutation();

        if ($part->runsMutation() && ! $mutation instanceof MutationScope) {
            throw new InvalidArgumentException('Mutation on changed files needs its scope: what changed since the base of the change.');
        }

        $gates = [];

        foreach (LocalProfile::gates($php, $composer) as $gate) {
            $full = match (true) {
                isset(self::NOT_RUN[$gate->number]) => new Gate($gate->number, $gate->title, [Step::notRun($gate->title, self::NOT_RUN[$gate->number])]),
                $gate->number === 5 => self::pest($gate, $php, $mutation, $part, $tally),
                $gate->number === 7 => self::storybook($gate),
                $gate->number === 8 => self::browser($gate, $php, $composer),
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
     * Gate 5: the local profile's steps, unless the part is a shard; the Mutation suite with
     * mutation testing, and reported as not run without it; and mutation on changed files, unless
     * the part is the default, which reports it as deferred, or the gates job, which reports it as
     * run in the shards.
     */
    private static function pest(Gate $gate, string $php, ?MutationScope $mutation, PrPart $part, ?MutationTally $tally): Gate
    {
        $suite = $part->runsMutationSuite()
            ? LocalProfile::suiteStep($php, self::MUTATION_SUITE, parallel: false)
            : Step::notRun(self::MUTATION_SUITE, self::MUTATION_DEFERRED);
        $steps = $part->runsGates() ? [...$gate->steps, $suite] : [];

        return new Gate(5, $gate->title, [
            ...$steps,
            ...($part->runsMutation() && $mutation instanceof MutationScope
                ? MutationSteps::for($mutation, $php, $tally)
                : [Step::notRun(MutationSteps::NAME, $part->mutationTesting ? self::MUTATION_IN_SHARDS : self::MUTATION_DEFERRED)]),
        ]);
    }

    /**
     * Gate 7: the steps of STORYBOOK_STEPS, each its npm script, in order. Every step runs also
     * after one fails, as every gate's steps do, so a missing story and a changed screenshot are
     * reported in one run.
     */
    private static function storybook(Gate $gate): Gate
    {
        $steps = [];

        foreach (self::STORYBOOK_STEPS as $name => $script) {
            $steps[] = Step::run($name, ['npm', 'run', $script]);
        }

        return new Gate($gate->number, $gate->title, $steps);
    }

    /**
     * Gate 8: `composer panel:build`, which builds the panel the browser tests open (PRD 13.4)
     * through the dev image, in place when the run is in the image already, as in CI; then the
     * Browser suite in Chromium, and its group BROWSER_MATRIX_GROUP in each of MATRIX_BROWSERS,
     * with skipped and incomplete tests failing each as in gate 5. The browser plugin starts
     * `playwright run-server`, which outlives a Pest process that dies of a fatal error and keeps
     * its output open, so each run is in a process group of its own that the runner kills when
     * the step ends.
     *
     * @param  list<string>  $composer
     */
    private static function browser(Gate $gate, string $php, array $composer): Gate
    {
        $matrix = [];

        foreach (self::MATRIX_BROWSERS as $name => $browser) {
            $matrix[] = Step::run(
                $name,
                [$php, 'vendor/bin/pest', '--testsuite='.self::BROWSER_SUITE, '--group='.self::BROWSER_MATRIX_GROUP, '--browser', $browser, ...LocalProfile::FAIL_FLAGS],
                ownProcessGroup: true,
            );
        }

        return new Gate($gate->number, $gate->title, [
            Step::run(self::PANEL_BUILD, [...$composer, self::PANEL_BUILD]),
            Step::run(
                self::BROWSER_SUITE,
                [$php, 'vendor/bin/pest', '--testsuite='.self::BROWSER_SUITE, ...LocalProfile::FAIL_FLAGS],
                ownProcessGroup: true,
            ),
            ...$matrix,
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
