<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
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
use DateTimeImmutable;
use Illuminate\Contracts\Redis\Factory;
use Redis;
use UnexpectedValueException;

/*
 * What the Valkey fragment store leaves in Valkey, field by field, under the run's prefix: the
 * fragment hash with the prefixed names of its index sets, the index sets a rewrite and a purge
 * clean up, the fence a later purge merges into, and the error of a script that failed on the
 * server.
 */

/**
 * @template T
 *
 * @param  Closure(Redis): T  $work
 * @return T
 */
function onValkey(Closure $work): mixed
{
    $client = app(ValkeyRun::class)->client();

    try {
        return $work($client);
    } finally {
        $client->close();
    }
}

function keysStore(FakeClock $clock): ValkeyFragmentStore
{
    return new ValkeyFragmentStore(app(Factory::class), $clock);
}

function keysEntry(int $seed): DependencyKey
{
    return DependencyKey::entry(new EntryId(new FakeIdGenerator(seed: $seed)->next()));
}

/**
 * @param  list<DependencyKey>  $dependencies
 */
function keysFragment(FakeClock $clock, array $dependencies, string $builtAt = '100'): Fragment
{
    return new Fragment(new FragmentKey('page:/a'), 'the body', $dependencies, new CommitPosition($builtAt), $clock->now()->add(new DateInterval('PT90S')));
}

function keysMicroseconds(DateTimeImmutable $time): string
{
    return $time->format('U').$time->format('u');
}

it('writes the fragment hash with every field, the prefixed names of its index sets and a field per dependency', function (): void {
    $clock = new FakeClock;
    $prefix = app(ValkeyRun::class)->prefix;
    $entry = keysEntry(1);
    $node = DependencyKey::node(new NodeId(new FakeIdGenerator(seed: 2)->next()));
    $fragment = keysFragment($clock, [$entry, $node]);
    [$a, $b] = array_map(static fn (DependencyKey $key): string => $key->toString(), $fragment->dependencies);

    expect(keysStore($clock)->write($fragment))->toBeInstanceOf(FragmentStored::class);

    $hash = onValkey(static fn (Redis $client): mixed => $client->hGetAll(ValkeyFragmentStore::FRAGMENT.'page:/a'));
    $members = onValkey(static fn (Redis $client): array => [
        $client->sMembers(ValkeyFragmentStore::INDEX.$a),
        $client->sMembers(ValkeyFragmentStore::INDEX.$b),
    ]);

    expect(is_array($hash) ? $hash : null)->toEqual([
        'key' => 'page:/a',
        'body' => 'the body',
        'built_at' => '100',
        'valid_until' => keysMicroseconds($fragment->validUntil),
        'deps' => '["'.$a.'","'.$b.'"]',
        'sets' => '["'.$prefix.ValkeyFragmentStore::INDEX.$a.'","'.$prefix.ValkeyFragmentStore::INDEX.$b.'"]',
        'd:'.$a => '1',
        'd:'.$b => '1',
    ])->and($members)->toBe([
        [$prefix.ValkeyFragmentStore::FRAGMENT.'page:/a'],
        [$prefix.ValkeyFragmentStore::FRAGMENT.'page:/a'],
    ]);
});

it('takes a rewritten fragment out of the index set of a dependency it no longer has', function (): void {
    $clock = new FakeClock;
    $prefix = app(ValkeyRun::class)->prefix;
    $old = keysEntry(1);
    $kept = keysEntry(2);
    $store = keysStore($clock);
    $store->write(keysFragment($clock, [$old, $kept]));

    $store->write(keysFragment($clock, [$kept], '200'));

    expect(onValkey(static fn (Redis $client): array => [
        $client->exists(ValkeyFragmentStore::INDEX.$old->toString()),
        $client->sMembers(ValkeyFragmentStore::INDEX.$kept->toString()),
        $client->hGet(ValkeyFragmentStore::FRAGMENT.'page:/a', 'd:'.$old->toString()),
        $client->hGet(ValkeyFragmentStore::FRAGMENT.'page:/a', 'built_at'),
    ]))->toBe([0, [$prefix.ValkeyFragmentStore::FRAGMENT.'page:/a'], false, '200']);
});

it('takes a purged fragment out of the index sets of its other dependencies and leaves only the fence', function (): void {
    $clock = new FakeClock;
    $run = app(ValkeyRun::class);
    $purged = keysEntry(1);
    $other = keysEntry(2);
    $store = keysStore($clock);
    $store->write(keysFragment($clock, [$purged, $other]));
    $until = $clock->now()->add(new DateInterval('PT60S'));

    $removed = $store->purge(new FragmentPurge($purged, new CommitPosition('500'), $until));

    expect(array_map(static fn (FragmentKey $key): string => $key->value, $removed))->toBe(['page:/a'])
        ->and($run->keys())->toBe([$run->prefix.ValkeyFragmentStore::FENCE.$purged->toString()])
        ->and(onValkey(static fn (Redis $client): mixed => $client->hGetAll(ValkeyFragmentStore::FENCE.$purged->toString())))
        ->toEqual(['position' => '500', 'until' => keysMicroseconds($until)])
        ->and($store->fragmentsOf($other))->toBe([]);
});

it('merges a purge into a live fence by the higher position, compared by length first, and the later end', function (): void {
    $clock = new FakeClock;
    $key = keysEntry(1);
    $store = keysStore($clock);
    $fence = static fn (): mixed => onValkey(static fn (Redis $client): mixed => $client->hGetAll(ValkeyFragmentStore::FENCE.$key->toString()));
    $late = $clock->now()->add(new DateInterval('PT60S'));
    $early = $clock->now()->add(new DateInterval('PT30S'));

    $store->purge(new FragmentPurge($key, new CommitPosition('900'), $late));
    $store->purge(new FragmentPurge($key, new CommitPosition('800'), $early));
    $lower = $fence();
    $store->purge(new FragmentPurge($key, new CommitPosition('1000'), $early));
    $longer = $fence();
    $ttl = onValkey(static fn (Redis $client): mixed => $client->pttl(ValkeyFragmentStore::FENCE.$key->toString()));

    expect($lower)->toEqual(['position' => '900', 'until' => keysMicroseconds($late)])
        ->and($longer)->toEqual(['position' => '1000', 'until' => keysMicroseconds($late)])
        ->and($ttl)->toBeGreaterThan(55_000)->toBeLessThanOrEqual(60_000);
});

it('reports the server\'s error when a script fails', function (): void {
    $clock = new FakeClock;
    $key = keysEntry(1);
    onValkey(static fn (Redis $client): mixed => $client->set(ValkeyFragmentStore::FENCE.$key->toString(), 'not a fence'));

    try {
        keysStore($clock)->write(keysFragment($clock, [$key]));
        $message = null;
    } catch (UnexpectedValueException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toStartWith('The fragment store\'s script failed: ')->toContain('WRONGTYPE');
});
