<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tooling\Mutation\Boundary\MutationPlanJson;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationPlan;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShard;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShards;
use InvalidArgumentException;

/*
 * The split of mutation on changed files into shards (Sylvester's decision of 1 October, M1-T66):
 * CI runs a job per shard, so a shard that leaves a changed file out, or two shards that both
 * mutate one, would change what the gate checks. The plans here are made of planted file lists.
 */

/**
 * A planted change: $fast files outside Adapter and Infrastructure and $postgres files in Adapter,
 * each with a size from a fixed sequence, so the plan is the same in every run.
 */
function plantedScope(int $fast, int $postgres = 0): MutationScope
{
    $sources = [];

    for ($number = 1; $number <= $fast; $number++) {
        $sources[] = new ChangedSource(sprintf('packages/core/src/Planted/Domain/Fast%04d.php', $number), sprintf('Cbox\Cms\Core\Planted\Domain\Fast%04d', $number), 200 + ($number * 7919) % 9000);
    }

    for ($number = 1; $number <= $postgres; $number++) {
        $sources[] = new ChangedSource(sprintf('packages/core/src/Planted/Adapter/Store%04d.php', $number), sprintf('Cbox\Cms\Core\Planted\Adapter\Store%04d', $number), 300 + ($number * 104729) % 12000);
    }

    return MutationScope::changed('abc123', $sources);
}

/**
 * Every path of every shard, in shard order, each as often as the shards list it.
 *
 * @return list<string>
 */
function shardedPaths(MutationPlan $plan): array
{
    $paths = [];

    foreach ($plan->shards as $shard) {
        array_push($paths, ...$shard->paths());
    }

    return $paths;
}

it('puts every changed file in exactly one shard, whatever the size of the change', function (int $fast, int $postgres): void {
    $scope = plantedScope($fast, $postgres);
    $plan = MutationShards::plan($scope);
    $paths = shardedPaths($plan);
    $expected = array_map(static fn (ChangedSource $source): string => $source->path, $scope->sources);
    $sorted = $paths;
    sort($sorted);

    expect($sorted)->toBe($expected)
        ->and(count(array_unique($paths)))->toBe(count($paths))
        ->and($plan->count())->toBe(MutationShards::count($fast + $postgres))
        ->and(array_map(static fn (MutationShard $shard): int => $shard->index, $plan->shards))->toBe(range(1, $plan->count()));

    foreach ($plan->shards as $shard) {
        expect($shard->count)->toBe($plan->count())
            ->and($fast + $postgres === 0 || $shard->sources !== [])->toBeTrue();
    }
})->with([
    'no change' => [0, 0],
    'one file' => [1, 0],
    'one Adapter file' => [0, 1],
    'one shard of both kinds' => [7, 3],
    'one task' => [24, 0],
    'two shards, one Adapter file' => [19, 1],
    'two shards, one plain file' => [1, 19],
    'a block, the size of M1' => [880, 109],
    'more files than the most shards hold' => [1500, 400],
]);

it('takes the number of shards from the number of changed files: one per 10, at least one and at most 100', function (): void {
    expect(MutationShards::FILES_PER_SHARD)->toBe(10)
        ->and(MutationShards::MAX_SHARDS)->toBe(100)
        ->and(MutationShards::count(0))->toBe(1)
        ->and(MutationShards::count(1))->toBe(1)
        ->and(MutationShards::count(10))->toBe(1)
        ->and(MutationShards::count(11))->toBe(2)
        ->and(MutationShards::count(989))->toBe(99)
        ->and(MutationShards::count(1000))->toBe(100)
        ->and(MutationShards::count(5000))->toBe(100);
});

it('makes the same plan from the same change, whatever the order the files were found in', function (): void {
    $scope = plantedScope(300, 40);
    $reversed = MutationScope::changed('abc123', array_reverse($scope->sources));

    expect(MutationPlanJson::encode(MutationShards::plan($reversed)))->toBe(MutationPlanJson::encode(MutationShards::plan($scope)));
});

it('gives the Adapter and Infrastructure files shards of their own after the others, in proportion to their bytes, so fewer shards run the Postgres suite', function (): void {
    $plan = MutationShards::plan(plantedScope(200, 40));
    $kinds = array_map(static fn (MutationShard $shard): array => array_values(array_unique(array_map(static fn (ChangedSource $source): bool => $source->needsPostgres(), $shard->sources))), $plan->shards);
    $postgresShards = count(array_filter($kinds, static fn (array $kind): bool => $kind === [true]));

    expect($plan->count())->toBe(24)
        ->and(array_filter($kinds, static fn (array $kind): bool => count($kind) !== 1))->toBe([])
        ->and($postgresShards)->toBeGreaterThanOrEqual(1)->toBeLessThan(24)
        ->and(array_slice($kinds, -$postgresShards))->each->toBe([true])
        ->and(array_slice($kinds, 0, 24 - $postgresShards))->each->toBe([false]);
});

it('keeps both kinds in one shard when the change has one shard', function (): void {
    $plan = MutationShards::plan(plantedScope(3, 2));

    expect($plan->count())->toBe(1)
        ->and($plan->shard(1)->paths())->toHaveCount(5);
});

it('deals the files out so the shards have about as many bytes', function (): void {
    $plan = MutationShards::plan(plantedScope(400));
    $bytes = array_map(static fn (MutationShard $shard): int => array_sum(array_map(static fn (ChangedSource $source): int => $source->size, $shard->sources)), $plan->shards);
    $sizes = array_map(static fn (ChangedSource $source): int => $source->size, plantedScope(400)->sources);
    sort($bytes);
    sort($sizes);

    expect($plan->count())->toBe(40)
        ->and((array_last($bytes) ?? 0) - ($bytes[0] ?? 0))->toBeLessThanOrEqual(array_last($sizes) ?? 0);
});

it('gives a change without a base one shard, which fails with the reason, and no sources', function (): void {
    $plan = MutationShards::plan(MutationScope::unresolved('CMS_CI_BASE_REF=nosuch names no commit'));

    expect($plan->count())->toBe(1)
        ->and($plan->failure)->toBe('CMS_CI_BASE_REF=nosuch names no commit')
        ->and($plan->scope(1)->failure)->toBe('CMS_CI_BASE_REF=nosuch names no commit')
        ->and($plan->sources())->toBe([]);
});

it('gives each shard a scope of its own sources since the plan\'s base, naming the shard', function (): void {
    $plan = MutationShards::plan(plantedScope(15));
    $scope = $plan->scope(2);

    expect($scope->base)->toBe('abc123 (shard 2 of 2)')
        ->and($scope->sources)->toBe($plan->shard(2)->sources)
        ->and(static fn (): MutationScope => $plan->scope(3))->toThrow(InvalidArgumentException::class, 'The plan has 2 shards, not a shard 3.');
});

it('round-trips a plan through its JSON, and gives the matrix of its shard numbers', function (): void {
    $plan = MutationShards::plan(plantedScope(22, 3));
    $json = MutationPlanJson::encode($plan);

    expect(MutationPlanJson::encode(MutationPlanJson::decode($json)))->toBe($json)
        ->and(MutationPlanJson::decode($json))->toEqual($plan)
        ->and(MutationPlanJson::matrix($plan))->toBe('[1,2,3]')
        ->and(MutationPlanJson::decode(MutationPlanJson::encode(MutationShards::plan(MutationScope::unresolved('no base')))))->toEqual(MutationShards::plan(MutationScope::unresolved('no base')));
});

it('refuses a plan that puts a file in two shards, numbers its shards out of order or has no shard', function (callable $make, string $message): void {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a file twice' => [static fn (): MutationPlan => new MutationPlan('abc', null, [
        new MutationShard(1, 2, [new ChangedSource('packages/a/src/A.php', 'A')]),
        new MutationShard(2, 2, [new ChangedSource('packages/a/src/A.php', 'A')]),
    ]), 'packages/a/src/A.php is in two shards of the plan.'],
    'out of order' => [static fn (): MutationPlan => new MutationPlan('abc', null, [new MutationShard(2, 2, []), new MutationShard(1, 2, [])]), 'numbered 1 to their count'],
    'no shard' => [static fn (): MutationPlan => new MutationPlan('abc', null, []), 'at least one shard'],
    'a shard outside its count' => [static fn (): MutationShard => new MutationShard(3, 2, []), 'Shard 3 of 2 is not a shard.'],
    'unsorted sources' => [static fn (): MutationShard => new MutationShard(1, 1, [new ChangedSource('packages/a/src/B.php', 'B'), new ChangedSource('packages/a/src/A.php', 'A')]), 'not sorted by path'],
    'sources without a base' => [static fn (): MutationPlan => new MutationPlan(null, 'no base', [new MutationShard(1, 1, [new ChangedSource('packages/a/src/A.php', 'A')])]), 'without a base has no sources'],
    'a JSON plan in another format' => [static fn (): MutationPlan => MutationPlanJson::decode('{"format": 2, "shards": []}'), 'not in format 1'],
]);

it('counts each byte of an Adapter or Infrastructure file twice for the share of shards it gets', function (): void {
    $sources = [];

    foreach (range(1, 100) as $number) {
        $sources[] = new ChangedSource(sprintf('packages/core/src/Even/Domain/Plain%03d.php', $number), sprintf('Cbox\\Cms\\Core\\Even\\Domain\\Plain%03d', $number), 1000);
        $sources[] = new ChangedSource(sprintf('packages/core/src/Even/Adapter/Store%03d.php', $number), sprintf('Cbox\\Cms\\Core\\Even\\Adapter\\Store%03d', $number), 1000);
    }

    $plan = MutationShards::plan(MutationScope::changed('abc123', $sources));
    $postgres = array_filter($plan->shards, static fn (MutationShard $shard): bool => $shard->sources[0]->needsPostgres());

    // 200 files make 20 shards; with equal bytes, the Adapter files' weighted share is two thirds.
    expect(MutationShards::POSTGRES_WEIGHT)->toBe(2)
        ->and($plan->count())->toBe(20)
        ->and($postgres)->toHaveCount(13);
});
