<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use Cbox\Cms\Tooling\Check\Domain\Step;

/**
 * Mutation on changed files, the PR profile's part of GUARDRAILS 9 and 10: Pest's `--mutate` on
 * the sources below packages/<package>/src that changed since the base of the change
 * (GitMutationScope), each step failing when a source it judges is below a score of 80 over its
 * mutations. CI splits the sources into shards (MutationShards) and runs these steps once per
 * shard, in a job of its own.
 *
 * The flags, as Pest 5 and pest-plugin-mutate 5.0 read them: `--everything` lets the run mutate
 * without covers() or mutates() in a test, and makes sure neither narrows what is mutated;
 * `--path` names the changed files, so only they are mutated, and every class, enum and trait in
 * them (with `--everything`, `--class` is ignored, and without it `--class` finds only `class` and
 * `trait` declarations). The minimum score is MutationReportReader's, not Pest's `--min`, because
 * a step judges some of the files its run mutates, and the step with Postgres counts the fast
 * suites' run as well. Coverage comes from PCOV, which tools/mutation/pcov.ini enables through
 * PHP_INI_SCAN_DIR for the run and every process it starts; the image loads PCOV but leaves it
 * off.
 *
 * The fast suites' step mutates every changed source against the fast suites with `--parallel`:
 * the tests and then the mutations run in parallel workers. It records every mutation's outcome in
 * a MutationLedger and judges nothing. The step with Postgres mutates every changed source again
 * against the Postgres suite alone, also with `--parallel`, and runs only the mutations the fast
 * suites did not catch (CaughtByFastSuites, SkipCaughtMutations): each worker, and each run of a
 * single mutation, has a TEST_TOKEN, and the RealPostgres harness gives each token a test database
 * of its own (TestDatabaseName), so the workers never share rows. It judges every changed source
 * and counts a mutation as caught when either run caught it: what one run of all the suites would
 * count, without a PHP process that holds every suite's tests and their coverage at once, which ran
 * out of memory. A class of any layer can have its tests in the Postgres suite, such as an Artisan
 * command or a DTO the Postgres adapters write, so every source is mutated against it (M1-T66;
 * before, only Adapter and Infrastructure were, and such a class scored 0). When the fast suites
 * caught every mutation, the Postgres suite cannot change the score, and the step passes without
 * running it. The step with Postgres leaves the mutations on the list of equivalent mutations out
 * of each source's score, and fails on an entry that no longer names a surviving mutation
 * (EquivalentMutations).
 */
final readonly class MutationSteps
{
    public const string NAME = 'Mutation on changed files';

    public const string FAST_NAME = 'Mutation on changed files, fast suites';

    public const string POSTGRES_NAME = 'Mutation on changed files, with Postgres';

    public const int MIN_SCORE = 80;

    /**
     * The suites the fast suites' step runs, in parallel, for every changed source.
     *
     * @var list<string>
     */
    public const array FAST_SUITES = ['Unit', 'Codecs', 'Contract', 'Actions', 'Arch'];

    /**
     * The suite the step with Postgres runs, in parallel, for the mutations the fast suites did not
     * catch.
     */
    public const string POSTGRES_SUITE = 'Postgres';

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
     * step when no source changed, and otherwise the fast suites' Pest run over every source, and
     * the Postgres suite's over the mutations it left.
     *
     * @param  string  $php  the PHP binary
     * @param  MutationTally|null  $tally  where the steps add the count of each changed source
     * @return list<Step>
     */
    public static function for(MutationScope $scope, string $php, ?MutationTally $tally = null): array
    {
        if ($scope->failure !== null) {
            return [Step::failed(self::NAME, $scope->failure)];
        }

        if ($scope->sources === []) {
            return [Step::passed(self::NAME, self::NO_CHANGES.' since '.$scope->base)];
        }

        $ledger = new MutationLedger;
        $equivalents = EquivalentMutations::kernel();

        return [
            self::step(self::FAST_NAME, $php, self::FAST_SUITES, $scope->sources, new MutationReportReader([], self::MIN_SCORE, records: $ledger)),
            self::step(
                self::POSTGRES_NAME,
                $php,
                [self::POSTGRES_SUITE],
                $scope->sources,
                new MutationReportReader($scope->sources, self::MIN_SCORE, counts: $ledger, tally: $tally, equivalents: $equivalents),
                precheck: new CaughtByFastSuites($scope->sources, $ledger, $tally, $equivalents),
            ),
        ];
    }

    /**
     * A Pest run with `--mutate --parallel` of $suites over $sources.
     *
     * @param  list<string>  $suites
     * @param  list<ChangedSource>  $sources
     */
    private static function step(string $name, string $php, array $suites, array $sources, MutationReportReader $reader, ?CaughtByFastSuites $precheck = null): Step
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
                '--parallel',
                '--everything',
                '--path='.implode(',', $paths),
            ],
            reader: $reader,
            environment: self::ENVIRONMENT,
            precheck: $precheck,
        );
    }
}
