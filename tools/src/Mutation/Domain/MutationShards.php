<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

/**
 * Splits mutation on changed files into shards by file, so CI runs a job per shard in parallel and
 * the run takes as long as its slowest shard (GUARDRAILS 10; Sylvester's decision of 1 October).
 * Nothing is left out: every changed source is in exactly one shard, and every shard runs both of
 * its steps (MutationSteps), the fast suites' and the Postgres suite's for what the fast suites
 * left.
 *
 * The number of shards comes from the number of changed files: one shard per FILES_PER_SHARD
 * files, at least one, so a change without sources still has a shard that says so, and at most
 * MAX_SHARDS. The split is a pure function of the scope, so the plan job and every shard job of one
 * commit make the same plan:
 *
 * - When there are several shards and the change has sources of both kinds, the sources in Adapter
 *   and Infrastructure get shards of their own, numbered after the others, in proportion to their
 *   share of the bytes with each of their bytes counted POSTGRES_WEIGHT times: the fast suites
 *   catch few of their mutations, so most of them run again against the Postgres suite, whose
 *   tests are slower. On the M1 diff, shards of such sources took about twice as long as shards of
 *   the same bytes of other sources.
 * - Within each group, the sources are dealt out largest first (by size, then path) to the shard
 *   with the fewest bytes so far (the lowest number on a tie), so the shards take about as long.
 */
final readonly class MutationShards
{
    /**
     * The changed files per shard. On the M1 diff, a shard of about 20 files took 4 to 7 minutes
     * of mutation on 16 CPUs and about twice that on the 4 of the declared runner, on top of the
     * job's setup and the suites' runs with coverage, so 10 keeps a shard within the 15 minutes of
     * GUARDRAILS 10 (PROGRESS.md, M1-T66).
     */
    public const int FILES_PER_SHARD = 10;

    /**
     * How many times a byte of a source in Adapter or Infrastructure counts towards the share of
     * shards its group gets, measured on the M1 diff (PROGRESS.md, M1-T66).
     */
    public const int POSTGRES_WEIGHT = 2;

    /**
     * The most shards one change is split into, well below the 256 jobs GitHub Actions lets one
     * matrix have.
     */
    public const int MAX_SHARDS = 100;

    /**
     * The number of shards for a number of changed files.
     */
    public static function count(int $files): int
    {
        return max(1, min(self::MAX_SHARDS, intdiv(max(0, $files) + self::FILES_PER_SHARD - 1, self::FILES_PER_SHARD)));
    }

    public static function plan(MutationScope $scope): MutationPlan
    {
        if ($scope->failure !== null) {
            return new MutationPlan(null, $scope->failure, [new MutationShard(1, 1, [])]);
        }

        $count = self::count(count($scope->sources));
        $postgres = $scope->sources(true);
        $fast = $scope->sources(false);

        if ($count === 1 || $postgres === [] || $fast === []) {
            $groups = self::deal($scope->sources, $count);
        } else {
            $postgresShards = self::postgresShards($count, $postgres, $fast);
            $groups = [...self::deal($fast, $count - $postgresShards), ...self::deal($postgres, $postgresShards)];
        }

        $shards = [];

        foreach ($groups as $position => $sources) {
            $shards[] = new MutationShard($position + 1, $count, $sources);
        }

        return new MutationPlan((string) $scope->base, null, $shards);
    }

    /**
     * The shards of the sources in Adapter and Infrastructure: their share of the weighted bytes, at least
     * one, and at most all but one, so the other sources keep a shard, and never more shards than
     * either group has sources, so no shard is empty. Asked only for two shards or more and both
     * kinds of sources.
     *
     * @param  list<ChangedSource>  $postgres
     * @param  list<ChangedSource>  $fast
     */
    private static function postgresShards(int $count, array $postgres, array $fast): int
    {
        $postgresBytes = self::POSTGRES_WEIGHT * self::bytes($postgres);
        $total = $postgresBytes + self::bytes($fast);
        $share = $total === 0 ? count($postgres) / (count($postgres) + count($fast)) : $postgresBytes / $total;

        return max(1, $count - count($fast), min($count - 1, count($postgres), (int) round($count * $share)));
    }

    /**
     * Deals the sources out to $shards shards, largest first, each to the shard with the fewest
     * bytes so far; each shard's sources sorted by path. The plan never asks for more shards than
     * there are sources, so every shard gets one.
     *
     * @param  list<ChangedSource>  $sources
     * @return list<list<ChangedSource>>
     */
    private static function deal(array $sources, int $shards): array
    {
        if ($shards === 0) {
            return [];
        }

        $order = $sources;
        usort($order, static fn (ChangedSource $a, ChangedSource $b): int => [$b->size, $a->path] <=> [$a->size, $b->path]);
        /** @var list<list<ChangedSource>> $groups */
        $groups = array_fill(0, $shards, []);
        $bytes = array_fill(0, $shards, 0);

        foreach ($order as $source) {
            $lightest = 0;

            foreach ($bytes as $position => $total) {
                if ($total < $bytes[$lightest]) {
                    $lightest = $position;
                }
            }

            $groups[$lightest][] = $source;
            $bytes[$lightest] += $source->size;
        }

        foreach ($groups as $position => $group) {
            usort($group, static fn (ChangedSource $a, ChangedSource $b): int => strcmp($a->path, $b->path));
            $groups[$position] = $group;
        }

        return $groups;
    }

    /**
     * @param  list<ChangedSource>  $sources
     */
    private static function bytes(array $sources): int
    {
        return array_sum(array_map(static fn (ChangedSource $source): int => $source->size, $sources));
    }
}
