<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Cache;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentFenced;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Cache\Adapter\ValkeyFragmentStore;
use Cbox\Cms\Core\Tests\Cache\Fakes\FixedRedisFactory;
use Cbox\Cms\Core\Tests\Cache\Fakes\ScriptCall;
use Cbox\Cms\Core\Tests\Cache\Fakes\ScriptedClient;
use Cbox\Cms\Core\Tests\Cache\Fakes\ScriptedRedis;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Closure;
use DateInterval;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use LogicException;
use Override;
use ReflectionProperty;
use UnexpectedValueException;

/*
 * The PHP side of the Valkey fragment store, on a phpredis connection whose client never
 * connects (ScriptedRedis): the exact keys and arguments each script gets, the TTL rounded up to
 * the millisecond, the prefix the index names carry, the answers the store refuses, the errors it
 * reports and the connections it refuses. The scripts themselves run in
 * packages/core/tests/Postgres/ValkeyFragmentStoreTest.php and ValkeyFragmentStoreKeysTest.php.
 */

function scriptedStore(ScriptedRedis|ScriptedClient $client, ?string $connection = null): ValkeyFragmentStore
{
    $phpredis = new PhpRedisConnection($client instanceof ScriptedRedis ? $client : new ScriptedRedis);

    if ($client instanceof ScriptedClient) {
        // A client that is not a Redis object, which the connection's own type does not allow.
        new ReflectionProperty(Connection::class, 'client')->setValue($phpredis, $client);
    }

    return new ValkeyFragmentStore(new FixedRedisFactory($phpredis), new FakeClock, $connection);
}

function scriptedEntry(int $seed): DependencyKey
{
    return DependencyKey::entry(new EntryId(new FakeIdGenerator(seed: $seed)->next()));
}

/**
 * @param  list<DependencyKey>  $dependencies
 */
function scriptedFragment(array $dependencies, int $microseconds): Fragment
{
    return new Fragment(new FragmentKey('page:/a'), 'body', $dependencies, new CommitPosition('100'), new FakeClock()->now()->modify(sprintf('+%d microseconds', $microseconds)));
}

/**
 * @param  Closure(): mixed  $work
 */
function scriptedFailure(Closure $work): UnexpectedValueException
{
    try {
        $work();
    } catch (UnexpectedValueException $exception) {
        return $exception;
    }

    throw new LogicException('The store did not refuse the answer.');
}

it('gives the write script the fragment hash, the index sets and fences, and the arguments in the order the script reads them', function (): void {
    $first = scriptedEntry(1);
    $second = DependencyKey::node(new NodeId(new FakeIdGenerator(seed: 2)->next()));
    [$a, $b] = array_map(static fn (DependencyKey $key): string => $key->toString(), scriptedFragment([$first, $second], 1_000_000)->dependencies);
    $client = new ScriptedRedis(answers: [[1]], prefix: 'run/7:');

    $outcome = scriptedStore($client)->write(scriptedFragment([$first, $second], 30_000_001));

    expect($outcome)->toBeInstanceOf(FragmentStored::class)
        ->and($client->calls)->toEqual([new ScriptCall(ValkeyFragmentStore::WRITE, [
            'cms:fragment:page:/a',
            'cms:fragments_of:'.$a,
            'cms:fragments_of:'.$b,
            'cms:purged:'.$a,
            'cms:purged:'.$b,
            '1767225600123456',
            'page:/a',
            'body',
            '100',
            '1767225630123457',
            '30001',
            '["'.$a.'","'.$b.'"]',
            '["run/7:cms:fragments_of:'.$a.'","run/7:cms:fragments_of:'.$b.'"]',
            $a,
            $b,
        ], 5)])
        ->and($client->calls[0]->args[10])->toBe('30001');
});

it('rounds the TTL up to the next millisecond and never below the fragment\'s validUntil', function (int $microseconds, string $ttl): void {
    $client = new ScriptedRedis(answers: [[1]]);

    scriptedStore($client)->write(scriptedFragment([scriptedEntry(1)], $microseconds));

    expect($client->calls[0]->args[8])->toBe($ttl);
})->with([
    'whole seconds' => [30_000_000, '30000'],
    'one microsecond over' => [30_000_001, '30001'],
    'one microsecond' => [1, '1'],
    'just under a millisecond' => [999, '1'],
    'a millisecond and a bit' => [1_001, '2'],
]);

it('stores index names without a prefix when the client is not a Redis object, and asks it for nothing but the script', function (): void {
    $key = scriptedEntry(1);
    $client = new ScriptedClient(answers: [[1], false]);
    $store = scriptedStore($client);

    $outcome = $store->write(scriptedFragment([$key], 30_000_000));
    $failure = scriptedFailure(static fn (): array => $store->fragmentsOf($key));

    expect($outcome)->toBeInstanceOf(FragmentStored::class)
        ->and($client->calls[0]->args[10])->toBe('["cms:fragments_of:'.$key->toString().'"]')
        ->and($failure->getMessage())->toBe('The fragment store\'s script failed: bool');
});

it('gives the purge script the index set and fence and the purge\'s arguments', function (): void {
    $key = scriptedEntry(1);
    $client = new ScriptedRedis(answers: [['page:/b', 'page:/a']]);

    $removed = scriptedStore($client)->purge(new FragmentPurge($key, new CommitPosition('700'), new FakeClock()->now()->add(new DateInterval('PT45S'))));

    expect(array_map(static fn (FragmentKey $fragment): string => $fragment->value, $removed))->toBe(['page:/a', 'page:/b'])
        ->and($client->calls)->toEqual([new ScriptCall(ValkeyFragmentStore::PURGE, [
            'cms:fragments_of:'.$key->toString(),
            'cms:purged:'.$key->toString(),
            '1767225600123456',
            '700',
            '1767225645123456',
            $key->toString(),
        ], 2)]);
});

it('reports the error of the failed script, never one an earlier call left behind', function (): void {
    $stale = new ScriptedRedis(answers: [false], lastError: 'ERR an earlier call');
    $failed = new ScriptedRedis(failWith: 'ERR the script failed');

    expect(scriptedFailure(static fn (): ?Fragment => scriptedStore($stale)->read(new FragmentKey('page:/a')))->getMessage())
        ->toBe('The fragment store\'s script failed: bool')
        ->and(scriptedFailure(static fn (): ?Fragment => scriptedStore($failed)->read(new FragmentKey('page:/a')))->getMessage())
        ->toBe('The fragment store\'s script failed: ERR the script failed');
});

it('refuses a script answer that is not a list', function (): void {
    $client = new ScriptedRedis(answers: [['a' => 'b']]);

    expect(scriptedFailure(static fn (): array => scriptedStore($client)->fragmentsOf(scriptedEntry(1)))->getMessage())
        ->toBe('The fragment store\'s script failed: array');
});

it('reads a fenced answer of the write script and refuses one it does not know, naming it', function (): void {
    $key = scriptedEntry(1);
    $dependency = $key->toString();
    $fenced = scriptedStore(new ScriptedRedis(answers: [[0, $dependency, '18446744073709551615']]))->write(scriptedFragment([$key], 30_000_000));
    $refused = static fn (array $answer): string => scriptedFailure(
        static fn (): mixed => scriptedStore(new ScriptedRedis(answers: [$answer]))->write(scriptedFragment([$key], 30_000_000)),
    )->getMessage();

    expect($fenced)->toBeInstanceOf(FragmentFenced::class)
        ->and($fenced instanceof FragmentFenced ? [$fenced->key->value, $fenced->dependency->toString(), $fenced->purgedAt->value, $fenced->builtAt->value] : null)
        ->toBe(['page:/a', $dependency, '18446744073709551615', '100'])
        ->and($refused([5, $dependency, '200']))->toBe('The fragment store\'s write got an answer it does not know: [5,"'.$dependency.'","200"]')
        ->and($refused([0, 5, '200']))->toBe('The fragment store\'s write got an answer it does not know: [0,5,"200"]')
        ->and($refused([0, $dependency, 200]))->toBe('The fragment store\'s write got an answer it does not know: [0,"'.$dependency.'",200]')
        ->and($refused([0, $dependency]))->toBe('The fragment store\'s write got an answer it does not know: [0,"'.$dependency.'"]')
        ->and($refused([0, $dependency, '200', 'more']))->toBe('The fragment store\'s write got an answer it does not know: [0,"'.$dependency.'","200","more"]')
        ->and($refused([1, 1]))->toBe('The fragment store\'s write got an answer it does not know: [1,1]')
        ->and($refused([]))->toBe('The fragment store\'s write got an answer it does not know: []');
});

it('refuses a stored fragment with a value of the wrong kind in any place', function (array $values, string $shown): void {
    $failure = scriptedFailure(static fn (): ?Fragment => scriptedStore(new ScriptedRedis(answers: [$values]))->read(new FragmentKey('home')));

    expect($failure->getMessage())->toBe('The fragment store\'s read got an answer it does not know: '.$shown);
})->with([
    'key' => [[7, 'body', '100', '1767225630123456', '[]'], '[7,"body","100","1767225630123456","[]"]'],
    'body' => [['home', 7, '100', '1767225630123456', '[]'], '["home",7,"100","1767225630123456","[]"]'],
    'build position' => [['home', 'body', 100, '1767225630123456', '[]'], '["home","body",100,"1767225630123456","[]"]'],
    'validUntil' => [['home', 'body', '100', 1767225630123456, '[]'], '["home","body","100",1767225630123456,"[]"]'],
    'dependencies not JSON of a list' => [['home', 'body', '100', '1767225630123456', '{"a":"e-x"}'], '["home","body","100","1767225630123456","{\"a\":\"e-x\"}"]'],
    'dependencies not an array' => [['home', 'body', '100', '1767225630123456', '5'], '["home","body","100","1767225630123456","5"]'],
    'a dependency not a string' => [['home', 'body', '100', '1767225630123456', '[5]'], '["home","body","100","1767225630123456","[5]"]'],
    'four values' => [['home', 'body', '100', '1767225630123456'], '["home","body","100","1767225630123456"]'],
]);

it('refuses a listed fragment key that is not a string', function (): void {
    $failure = scriptedFailure(static fn (): array => scriptedStore(new ScriptedRedis(answers: [['home', 5]]))->fragmentsOf(scriptedEntry(1)));

    expect($failure->getMessage())->toBe('The fragment store\'s fragmentsOf got an answer it does not know: ["home",5]');
});

it('shows an answer with bytes that are not UTF-8 with the replacement character', function (): void {
    $failure = scriptedFailure(static fn (): array => scriptedStore(new ScriptedRedis(answers: [["home\xff", 5]]))->fragmentsOf(scriptedEntry(1)));

    expect($failure->getMessage())->toBe('The fragment store\'s fragmentsOf got an answer it does not know: ["home\ufffd",5]');
});

it('refuses a connection that is not phpredis to one primary, naming the connection', function (): void {
    $predis = new class extends Connection
    {
        /**
         * @param  array<mixed>|string  $channels
         * @param  Closure(mixed...): mixed  $callback
         */
        #[Override]
        public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}
    };
    $cluster = new PhpRedisClusterConnection(new ScriptedRedis);
    $refusal = static function (Connection $connection, ?string $name): string {
        try {
            new ValkeyFragmentStore(new FixedRedisFactory($connection), new FakeClock, $name)->fragmentsOf(scriptedEntry(1));
        } catch (LogicException $exception) {
            return $exception->getMessage();
        }

        return 'accepted';
    };

    expect($refusal($predis, 'cache'))->toBe(sprintf('The fragment store needs a phpredis connection to one Valkey primary; the Redis connection [cache] is a %s.', get_debug_type($predis)))
        ->and($refusal($cluster, null))->toBe('The fragment store needs a phpredis connection to one Valkey primary; the Redis connection [default] is a Illuminate\Redis\Connections\PhpRedisClusterConnection.');
});

it('asks the factory for the connection it was given', function (): void {
    $factory = new FixedRedisFactory(new PhpRedisConnection(new ScriptedRedis(answers: [[]])));

    new ValkeyFragmentStore($factory, new FakeClock, 'fragments')->fragmentsOf(scriptedEntry(1));

    expect($factory->names)->toBe(['fragments']);
});
