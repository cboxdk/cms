<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * The declared plan of the shards of the slow suites of the PR profile: the Postgres suite of
 * gate 5 and the Browser suite of gate 8, which together take most of a CI run's time. B2 roughly
 * doubles the Postgres suite and adds nine Browser suites, and the gates part of B1's run already
 * took 14 minutes 50 seconds of the 15-minute budget of GUARDRAILS 10, so CI runs them in SHARDS
 * parts of its own, in parallel jobs, instead of dropping or thinning a gate: add shards or
 * parallelise, never drop a gate (GUARDRAILS 10, and bin/ci's own message over the budget).
 *
 * The plan is declared, not computed: the number of shards is the same for every run, so
 * `.github/workflows/ci.yml` can name the matrix and the verdict knows which shards must report
 * (ShardVerdict). bin/ci and ci.yml carry the same number, and
 * tests/Feature/Tooling/Ci/CiWorkflowTest.php holds the three to each other.
 *
 * A shard runs its share of the sharded suites with Pest's own `--shard=<i>/<n>`, which splits a
 * suite by test class over the shards and is time-balanced when `tests/.pest/shards.json` holds
 * timings. Each shard runs the steps of PREPARES as well, because its share needs them: the
 * Browser suite opens the panel's pages, which `composer panel:build` builds.
 */
final readonly class ShardPlan
{
    /**
     * How many shards the sharded suites run in, and so how many shard jobs ci.yml runs.
     */
    public const int SHARDS = 4;

    /**
     * The steps the plan shards, by the gate they belong to: the Postgres suite of gate 5 and the
     * Browser suite of gate 8. The Browser suite's matrix of Firefox and WebKit runs the small
     * group browser-matrix and stays in the gates part, where Playwright starts those browsers
     * once instead of once per shard.
     *
     * @var array<int, list<string>>
     */
    public const array SUITES = [
        5 => [LocalProfile::POSTGRES_SUITE],
        8 => [PrProfile::BROWSER_SUITE],
    ];

    /**
     * The steps a shard runs besides its share, because the share needs them.
     *
     * @var list<string>
     */
    public const array PREPARES = [PrProfile::PANEL_BUILD];

    /**
     * The steps of one gate that the plan shards; empty for a gate it does not shard.
     *
     * @return list<string>
     */
    public static function steps(int $gate): array
    {
        return self::SUITES[$gate] ?? [];
    }

    /**
     * Every sharded step, in the order of the gates.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_merge(...array_values(self::SUITES));
    }

    /**
     * The shards of the plan, numbered from 1.
     *
     * @return list<int>
     */
    public static function indexes(): array
    {
        return range(1, self::SHARDS);
    }

    /**
     * Pest's option that limits a suite to shard $shard of $shards.
     */
    public static function option(int $shard, int $shards): string
    {
        if ($shards < 1 || $shard < 1 || $shard > $shards) {
            throw new InvalidArgumentException("Shard {$shard} of {$shards} is not a shard: it is numbered from 1 to the number of shards.");
        }

        return "--shard={$shard}/{$shards}";
    }

    /**
     * What the plan shards, in words, such as "the Postgres and Browser suites".
     */
    public static function describe(): string
    {
        $names = self::names();
        $last = array_pop($names);

        return 'the '.($names === [] ? (string) $last : implode(', ', $names).' and '.$last).' suites';
    }

    /**
     * The sharded steps that ran in a report, passed or failed: what a shard's report tells the
     * verdict it did (ShardReport).
     *
     * @return list<string>
     */
    public static function ran(CheckReport $report): array
    {
        $ran = [];

        foreach (self::SUITES as $gate => $steps) {
            foreach ($steps as $name) {
                $step = $report->gate($gate)?->step($name);

                if ($step instanceof StepResult && ($step->status === StepStatus::Pass || $step->status === StepStatus::Fail)) {
                    $ran[] = $name;
                }
            }
        }

        return $ran;
    }
}
