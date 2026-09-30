<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;

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
     * @param  string  $php  the PHP binary
     * @param  list<string>  $composer  the command that runs Composer
     * @param  MutationScope  $mutation  what changed since the base of the change, for mutation on changed files
     * @return list<Gate>
     */
    public static function gates(string $php, array $composer, MutationScope $mutation): array
    {
        $gates = [];

        foreach (LocalProfile::gates($php, $composer) as $gate) {
            $gates[] = match (true) {
                isset(self::NOT_RUN[$gate->number]) => new Gate($gate->number, $gate->title, [Step::notRun($gate->title, self::NOT_RUN[$gate->number])]),
                $gate->number === 5 => new Gate(5, $gate->title, [
                    ...$gate->steps,
                    LocalProfile::suiteStep($php, self::MUTATION_SUITE, parallel: false),
                    ...MutationSteps::for($mutation, $php),
                ]),
                $gate->number === 8 => self::browser($gate, $php),
                $gate->number === 9 => self::audit($gate, $composer),
                $gate->number === 10 => self::docs($gate, $composer),
                default => $gate,
            };
        }

        return $gates;
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
