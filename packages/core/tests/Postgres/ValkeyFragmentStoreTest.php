<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentFenced;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Cache\Adapter\ValkeyFragmentStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Closure;
use DateInterval;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Event;

/*
 * The fragment store in Valkey (PRD 8.12 point 1, 9.3) beyond the shared suite: each write and
 * purge is one script call, the keys it leaves under the run's prefix and their TTLs, and index
 * entries that outlive their fragment.
 */

/**
 * The Redis commands the store sends while $work runs, by name, through the connection's events.
 *
 * @param  Closure(): mixed  $work
 * @return list<string>
 */
function fragmentStoreCommands(Closure $work): array
{
    $commands = [];
    Event::listen(CommandExecuted::class, static function (CommandExecuted $executed) use (&$commands): void {
        $commands[] = strtolower($executed->command);
    });

    $work();

    return $commands;
}

function fragmentStoreUnderTest(FakeClock $clock): FragmentStore
{
    $manager = app(RedisManager::class);
    $manager->enableEvents();

    return new ValkeyFragmentStore(app(Factory::class), $clock);
}

function fragmentAt(FakeClock $clock, string $key, DependencyKey $dependency, string $builtAt, int $seconds = 300): Fragment
{
    return new Fragment(new FragmentKey($key), 'body', [$dependency], new CommitPosition($builtAt), $clock->now()->add(new DateInterval(sprintf('PT%dS', $seconds))));
}

function fragmentDependency(int $seed = 1): DependencyKey
{
    return DependencyKey::entry(new EntryId(new FakeIdGenerator(seed: $seed)->next()));
}

it('refuses a fragment built at or below a purge and stores one built above it, each in one script call', function (): void {
    $clock = new FakeClock;
    $store = fragmentStoreUnderTest($clock);
    $key = fragmentDependency();

    $purge = fragmentStoreCommands(fn (): array => $store->purge(new FragmentPurge($key, new CommitPosition('5000'), $clock->now()->add(new DateInterval('PT60S')))));
    $outcomes = [];
    $writes = [];

    foreach (['4999', '5000', '5001'] as $builtAt) {
        $writes[$builtAt] = fragmentStoreCommands(function () use ($store, $clock, $key, $builtAt, &$outcomes): void {
            $outcomes[$builtAt] = $store->write(fragmentAt($clock, 'page:/front', $key, $builtAt));
        });
    }

    expect($purge)->toBe(['eval'])
        ->and($writes)->toBe(['4999' => ['eval'], '5000' => ['eval'], '5001' => ['eval']])
        ->and($outcomes['4999'])->toBeInstanceOf(FragmentFenced::class)
        ->and($outcomes['5000'])->toBeInstanceOf(FragmentFenced::class)
        ->and($outcomes['5001'])->toBeInstanceOf(FragmentStored::class)
        ->and($store->read(new FragmentKey('page:/front'))?->builtAt->value)->toBe('5001');
});

it('compares positions above 2^53 exactly, where Lua\'s numbers would round', function (): void {
    $clock = new FakeClock;
    $store = fragmentStoreUnderTest($clock);
    $key = fragmentDependency();
    $store->purge(new FragmentPurge($key, new CommitPosition('9007199254740993'), $clock->now()->add(new DateInterval('PT60S'))));

    expect($store->write(fragmentAt($clock, 'page:/a', $key, '9007199254740992')))->toBeInstanceOf(FragmentFenced::class)
        ->and($store->write(fragmentAt($clock, 'page:/a', $key, '9007199254740993')))->toBeInstanceOf(FragmentFenced::class)
        ->and($store->write(fragmentAt($clock, 'page:/a', $key, '9007199254740994')))->toBeInstanceOf(FragmentStored::class);
});

it('keeps the fragment, its index and its fence under the connection\'s prefix, each with the TTL of what it is for', function (): void {
    $clock = new FakeClock;
    $store = fragmentStoreUnderTest($clock);
    $run = app(ValkeyRun::class);
    $key = fragmentDependency();
    $store->write(fragmentAt($clock, 'page:/a', $key, '100', seconds: 30));
    $store->write(fragmentAt($clock, 'page:/b', $key, '100', seconds: 90));
    $other = DependencyKey::node(new NodeId(new FakeIdGenerator(seed: 2)->next()));
    $store->purge(new FragmentPurge($other, new CommitPosition('700'), $clock->now()->add(new DateInterval('PT45S'))));

    $client = $run->client();

    try {
        $ttls = [
            'fragment' => $client->pttl(ValkeyFragmentStore::FRAGMENT.'page:/a'),
            'index' => $client->pttl(ValkeyFragmentStore::INDEX.$key->toString()),
            'fence' => $client->pttl(ValkeyFragmentStore::FENCE.$other->toString()),
        ];
        $fence = $client->hGetAll(ValkeyFragmentStore::FENCE.$other->toString());
    } finally {
        $client->close();
    }

    expect($run->keys())->toBe([
        $run->prefix.ValkeyFragmentStore::FRAGMENT.'page:/a',
        $run->prefix.ValkeyFragmentStore::FRAGMENT.'page:/b',
        $run->prefix.ValkeyFragmentStore::INDEX.$key->toString(),
        $run->prefix.ValkeyFragmentStore::FENCE.$other->toString(),
    ])->and($ttls['fragment'])->toBeGreaterThan(25_000)->toBeLessThanOrEqual(30_000)
        ->and($ttls['index'])->toBeGreaterThan(85_000)->toBeLessThanOrEqual(90_000)
        ->and($ttls['fence'])->toBeGreaterThan(40_000)->toBeLessThanOrEqual(45_000)
        ->and($fence['position'] ?? null)->toBe('700');
});

it('ignores an index entry whose fragment expired and was written again with other dependencies', function (): void {
    $clock = new FakeClock;
    $store = fragmentStoreUnderTest($clock);
    $run = app(ValkeyRun::class);
    $old = fragmentDependency(1);
    $new = fragmentDependency(2);
    $store->write(fragmentAt($clock, 'page:/a', $old, '100'));

    // The fragment's TTL ran out before its index set's did.
    $client = $run->client();

    try {
        $client->del(ValkeyFragmentStore::FRAGMENT.'page:/a');
    } finally {
        $client->close();
    }

    $store->write(fragmentAt($clock, 'page:/a', $new, '100'));

    expect($store->fragmentsOf($old))->toBe([])
        ->and($store->purge(new FragmentPurge($old, new CommitPosition('150'), $clock->now()->add(new DateInterval('PT60S')))))->toBe([])
        ->and($store->read(new FragmentKey('page:/a'))?->dependencies[0]->toString())->toBe($new->toString())
        ->and(array_map(static fn (FragmentKey $key): string => $key->value, $store->fragmentsOf($new)))->toBe(['page:/a']);
});

it('is bound to the FragmentStore contract by default', function (): void {
    expect(app(FragmentStore::class))->toBeInstanceOf(ValkeyFragmentStore::class);
});
