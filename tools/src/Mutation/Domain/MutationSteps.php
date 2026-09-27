<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use Cbox\Cms\Tooling\Check\Domain\Step;

/**
 * Mutation on changed files, the PR profile's part of GUARDRAILS 9 and 10: Pest's `--mutate` on
 * the sources below packages/<package>/src that changed since the merge base of CMS_CI_BASE_REF
 * and HEAD, with `--min=80`.
 *
 * The flags, as Pest 5 and pest-plugin-mutate 5.0 read them: `--everything` lets the run mutate
 * without covers() or mutates() in a test, and makes sure neither narrows what is mutated;
 * `--path` names the changed files, so only they are mutated, and every class, enum and trait in
 * them (with `--everything`, `--class` is ignored, and without it `--class` finds only `class` and
 * `trait` declarations); `--min=80` fails the run below a score of 80, and
 * `--ignore-min-score-on-zero-mutations` passes a run whose files have nothing to mutate, such as
 * an interface, instead of scoring it 0. Coverage comes from PCOV, which tools/mutation/pcov.ini
 * enables through PHP_INI_SCAN_DIR for the run and every process it starts; the image loads PCOV
 * but leaves it off.
 *
 * The sources in Adapter and Infrastructure run against the Postgres suite and the fast suites,
 * serially, because the RealPostgres harness shares one database. The others run against the
 * fast suites with `--parallel`: the tests and then the mutations run in parallel workers.
 */
final readonly class MutationSteps
{
    public const string NAME = 'Mutation on changed files';

    public const string FAST_NAME = 'Mutation on changed files, fast suites';

    public const string POSTGRES_NAME = 'Mutation on changed files, with Postgres';

    public const int MIN_SCORE = 80;

    /**
     * The suites whose tests may kill a mutation of a class outside Adapter and Infrastructure.
     *
     * @var list<string>
     */
    public const array FAST_SUITES = ['Unit', 'Codecs', 'Contract', 'Actions', 'Arch'];

    /**
     * The suites whose tests may kill a mutation of a class in Adapter or Infrastructure.
     *
     * @var list<string>
     */
    public const array POSTGRES_SUITES = ['Unit', 'Codecs', 'Contract', 'Postgres', 'Actions', 'Arch'];

    /**
     * The directory with pcov.ini, relative to the checkout. A leading colon in PHP_INI_SCAN_DIR
     * keeps the image's own directory.
     */
    public const string PCOV_INI_DIRECTORY = 'tools/mutation';

    /**
     * Set for the Pest run: PCOV on, and the report plugin on.
     *
     * @var array<string, string>
     */
    public const array ENVIRONMENT = [
        'PHP_INI_SCAN_DIR' => ':'.self::PCOV_INI_DIRECTORY,
        'CMS_MUTATION_REPORT' => '1',
    ];

    public const string NO_CHANGES = '0 changed classes';

    /**
     * The steps of mutation on changed files: a failing step when the base is missing, a passing
     * step when no source changed, and otherwise a Pest run for the fast suites and one with
     * Postgres, each only when it has sources.
     *
     * @param  string  $php  the PHP binary
     * @return list<Step>
     */
    public static function for(MutationScope $scope, string $php): array
    {
        if ($scope->failure !== null) {
            return [Step::failed(self::NAME, $scope->failure)];
        }

        if ($scope->sources === []) {
            return [Step::passed(self::NAME, self::NO_CHANGES.' since '.$scope->base)];
        }

        $steps = [];
        $fast = $scope->sources(false);
        $postgres = $scope->sources(true);

        if ($fast !== []) {
            $steps[] = self::step(self::FAST_NAME, $php, self::FAST_SUITES, $fast, parallel: true);
        }

        if ($postgres !== []) {
            $steps[] = self::step(self::POSTGRES_NAME, $php, self::POSTGRES_SUITES, $postgres, parallel: false);
        }

        return $steps;
    }

    /**
     * @param  list<string>  $suites
     * @param  non-empty-list<ChangedSource>  $sources
     */
    private static function step(string $name, string $php, array $suites, array $sources, bool $parallel): Step
    {
        $paths = array_map(static fn (ChangedSource $source): string => $source->path, $sources);

        return Step::run(
            $name,
            [
                $php,
                'vendor/bin/pest',
                '--testsuite='.implode(',', $suites),
                '--fail-on-skipped',
                '--fail-on-incomplete',
                '--mutate',
                ...($parallel ? ['--parallel'] : []),
                '--everything',
                '--path='.implode(',', $paths),
                '--min='.self::MIN_SCORE,
                '--ignore-min-score-on-zero-mutations',
            ],
            reader: new MutationReportReader($sources, self::MIN_SCORE),
            environment: self::ENVIRONMENT,
        );
    }
}
