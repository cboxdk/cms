<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * The PR profile of GUARDRAILS 10 as CI runs it today, through `bin/ci`: the steps of gates 1 to
 * 6 from the local profile, unchanged, gate 8 (the Browser suite) and gate 9 (composer audit and
 * npm audit), and gates 7, 10 and 11 and mutation on changed files reported as not run, each with
 * the reason. GUARDRAILS 10 wants every gate that did not run reported explicitly; a gate that
 * starts running in CI moves out of NOT_RUN.
 */
final readonly class PrProfile
{
    /**
     * The gates of the PR profile that CI does not run yet, by number, with the reason.
     */
    public const array NOT_RUN = [
        7 => 'not run in CI yet: there is no panel UI or Storybook before the panel skeleton (B1)',
        10 => 'not run in CI yet: there is no check for the documentation of extension points (PRD 14.4)',
        11 => 'not a command: review by someone other than the author needs a remote with branch protection, and there is no remote',
    ];

    /**
     * Mutation on changed files belongs to the PR profile. It is reported under gate 5, the Pest gate.
     */
    public const string MUTATION = 'Mutation on changed files';

    public const string MUTATION_NOT_RUN = 'not run in CI yet: mutation testing is not set up';

    /**
     * The Pest suite of gate 8, one of LocalProfile::OTHER_SUITES.
     */
    public const string BROWSER_SUITE = 'Browser';

    /**
     * @param  string  $php  the PHP binary
     * @param  list<string>  $composer  the command that runs Composer
     * @return list<Gate>
     */
    public static function gates(string $php, array $composer): array
    {
        $gates = [];

        foreach (LocalProfile::gates($php, $composer) as $gate) {
            $gates[] = match (true) {
                isset(self::NOT_RUN[$gate->number]) => new Gate($gate->number, $gate->title, [Step::notRun($gate->title, self::NOT_RUN[$gate->number])]),
                $gate->number === 5 => new Gate(5, $gate->title, [...$gate->steps, Step::notRun(self::MUTATION, self::MUTATION_NOT_RUN)]),
                $gate->number === 8 => self::browser($gate, $php),
                $gate->number === 9 => self::audit($gate, $composer),
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
                [$php, 'vendor/bin/pest', '--testsuite='.self::BROWSER_SUITE, '--fail-on-skipped', '--fail-on-incomplete'],
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
}
