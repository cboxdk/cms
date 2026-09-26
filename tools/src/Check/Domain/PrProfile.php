<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * The PR profile of GUARDRAILS 10 as CI runs it today, through `bin/ci`: the steps of gates 1 to
 * 6 from the local profile, unchanged, and gates 7 to 11 and mutation on changed files reported
 * as not run, each with the reason. GUARDRAILS 10 wants every gate that did not run reported
 * explicitly; a gate that starts running in CI moves out of NOT_RUN.
 */
final readonly class PrProfile
{
    /**
     * The gates of the PR profile that CI does not run yet, by number, with the reason.
     */
    public const array NOT_RUN = [
        7 => 'not run in CI yet: there is no panel UI or Storybook before the panel skeleton (B1)',
        8 => 'not run in CI yet: bin/ci runs gates 1 to 6; the Browser suite runs with vendor/bin/pest --testsuite=Browser',
        9 => 'not run in CI yet: composer audit and npm audit are not part of bin/ci (PRD 13.8)',
        10 => 'not run in CI yet: there is no check for the documentation of extension points (PRD 14.4)',
        11 => 'not a command: review by someone other than the author needs a remote with branch protection, and there is no remote',
    ];

    /**
     * Mutation on changed files belongs to the PR profile. It is reported under gate 5, the Pest gate.
     */
    public const string MUTATION = 'Mutation on changed files';

    public const string MUTATION_NOT_RUN = 'not run in CI yet: mutation testing is not set up';

    /**
     * @param  string  $php  the PHP binary
     * @param  list<string>  $composer  the command that runs Composer
     * @param  list<string>  $phpunitSuites  the names of the test suites in phpunit.xml
     * @return list<Gate>
     */
    public static function gates(string $php, array $composer, array $phpunitSuites): array
    {
        $gates = [];

        foreach (LocalProfile::gates($php, $composer, $phpunitSuites) as $gate) {
            $gates[] = match (true) {
                isset(self::NOT_RUN[$gate->number]) => new Gate($gate->number, $gate->title, [Step::notRun($gate->title, self::NOT_RUN[$gate->number])]),
                $gate->number === 5 => new Gate(5, $gate->title, [...$gate->steps, Step::notRun(self::MUTATION, self::MUTATION_NOT_RUN)]),
                default => $gate,
            };
        }

        return $gates;
    }
}
