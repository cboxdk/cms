<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * The local profile of GUARDRAILS 10, `composer check`: gates 1 to 6, in order. Gates 7 to 11
 * belong to the PR profile and are listed as not run, so the report says what was left out.
 *
 * Each gate runs the same Composer or npm script a developer runs by hand, so a gate's command
 * is defined once. Gate 5 first checks that vendor/ is the installation composer.lock and
 * composer.json describe (`composer install:check`), so a checkout that moved without
 * `composer install` fails with the fix instead of failing a suite at load with "Class not
 * found". It then runs each Pest suite on its own and fails a suite with a skipped or incomplete
 * test, so a missing service fails the gate instead of skipping it.
 */
final readonly class LocalProfile
{
    /**
     * The Pest suites of gate 5, in the order they run.
     *
     * @var list<string>
     */
    public const array SUITES = ['Unit', 'Codecs', 'Contract', 'Postgres', 'Arch', 'Actions'];

    /**
     * The suites that are not part of the local profile's gate 5. Browser is gate 8, in the PR
     * profile. Mutation holds the tests that need a coverage driver, which the PR profile runs in
     * gate 5 next to mutation on changed files; the host PHP of a developer has none.
     *
     * @var list<string>
     */
    public const array OTHER_SUITES = ['Browser', 'Mutation'];

    /**
     * The step of gate 5 that runs before the suites: `composer install:check`.
     */
    public const string INSTALLATION = 'Installation';

    public const string OUTSIDE_PROFILE = 'not in the local profile; the PR profile runs it (GUARDRAILS 10)';

    /**
     * @param  string  $php  the PHP binary
     * @param  list<string>  $composer  the command that runs Composer, such as [PHP_BINARY, getenv('COMPOSER_BINARY')]
     * @return list<Gate>
     */
    public static function gates(string $php, array $composer): array
    {
        $pest = static fn (string $suite): Step => self::suiteStep($php, $suite);

        return [
            new Gate(1, 'Pint and Prettier', [
                Step::run('Pint', [...$composer, 'lint:check']),
                Step::run('Prettier', ['npm', 'run', 'format:check']),
            ]),
            new Gate(2, 'Rector', [
                Step::run('Rector', [...$composer, 'rector:check']),
            ]),
            new Gate(3, 'PHPStan', [
                Step::run('PHPStan', [...$composer, 'analyse']),
            ]),
            new Gate(4, 'tsc and ESLint', [
                Step::run('tsc', ['npm', 'run', 'typecheck']),
                Step::run('ESLint', ['npm', 'run', 'lint']),
            ]),
            new Gate(5, 'Pest', [
                Step::run(self::INSTALLATION, [...$composer, 'install:check']),
                ...array_map($pest, self::SUITES),
            ]),
            new Gate(6, 'Generated code', [
                Step::run('check:generated', [...$composer, 'check:generated']),
            ]),
            self::outside(7, 'Storybook, visual regression and axe'),
            self::outside(8, 'Browser tests'),
            self::outside(9, 'composer audit and npm audit'),
            self::outside(10, 'Documentation for new extension points'),
            self::outside(11, 'Review of changed checks'),
        ];
    }

    /**
     * The step that runs one Pest suite, failing on a skipped or incomplete test.
     */
    public static function suiteStep(string $php, string $suite): Step
    {
        return Step::run($suite, [$php, 'vendor/bin/pest', '--testsuite='.$suite, '--fail-on-skipped', '--fail-on-incomplete']);
    }

    private static function outside(int $number, string $title): Gate
    {
        return new Gate($number, $title, [Step::notRun($title, self::OUTSIDE_PROFILE)]);
    }
}
